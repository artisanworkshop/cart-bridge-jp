#!/usr/bin/env perl
# gate-record.sh が使う G<n>.md のパーサー。記録を JSON にして標準出力へ出す（日本語のラベルを扱うので awk ではなく UTF-8 で読む）。
# 使い方: gate-record-parse.pl <G<n>.md>   （直接呼ぶ必要はない）
# 指摘が 0 件のラウンドは、最初の指摘の見出しより前の「指摘なし: <確認した内容>」の行で明示する（見出しが無いだけでは通さない）。
# 値は HTML コメントを除いた後の文字列から取る（描画されない語で判定や承認が決まらないように）。コードフェンス（``` / ~~~）の
# 中は見出し・ラベルとして解釈しない。閉じていないコメント／フェンスは以降を黙って飲み込むので、unterminated として報告する。
use strict;
use warnings;
use utf8;
use JSON::PP;
use Time::Local qw(timegm);
my $file = shift @ARGV;
open(my $fh, '<:encoding(UTF-8)', $file) or do { print STDERR "cannot read $file: $!\n"; exit 3 };
my %label = ('要旨' => 'summary', '判定' => 'verdict', '対応' => 'action', 'コミット' => 'commit', 'スレッド' => 'thread');
my (@header, @verify, @findings, @bad, @todo);
my ($title, $section, $cur, $lab, $incomment, $infence, $ln) = ('', '', undef, undef, 0, 0, 0);
my ($fchar, $flen) = ('', 0);
my ($pr_number, $round);
# ヘッダの `- PR: #<n> / 対象 HEAD: <sha>` の行番号と対象 HEAD、`- 承認: <UTC 時刻 | auto-commit>`（確認ゲートを通した印。
# `gate-record.sh approve` が書く）の行番号。値は最初の 1 行から読み、2 行以上あれば件数（pr_count・approval_count）で知らせる（呼び出し側が
# 曖昧な記録として拒否する。先頭の古い auto-commit 行が後ろの時刻承認を隠すのを防ぐ）。コメント・コードフェンスの中は数えない（$struct）:
# approve はこの行番号で挿入・置換するので、パーサと書き手の解釈が食い違うと、コメントの中に承認行を書いてしまう。
my ($pr_line, $target_head, $approval_raw, $approval_line);
my ($pr_count, $approval_count) = (0, 0);
my $has_section = 0;
my ($no_findings, $no_findings_text) = (0, '');
# 判定語の直後に漢字・かな・長音が続く場合（修正不要・保留中・修正しない）は別の語なので判定語として認めない。句読点（。、）は許す:
# 裸の \p{Han} 等は Script_Extensions の意味になり U+3001/3002 にも一致するため、Script= で書く。
my $boundary = qr/(?![\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\x{30FC}])/;
sub trim { my $s = shift; $s =~ s/^\s+//; $s =~ s/\s+$//; return $s }
sub flush {
  return unless $cur;
  my %f = %$cur;
  $f{$_} = trim($f{$_}) for qw(summary verdict action commit thread);
  (my $v = $f{verdict}) =~ s/^[\s*_`]+//;
  $f{kind} = $v eq '' ? 'missing'
    : $v =~ /^(?:修正済み|修正)$boundary/ ? 'fixed'
    : $v =~ /^保留$boundary/ ? 'held'
    : $v =~ /^対応不要$boundary/ ? 'dismissed'
    : 'unknown';
  $f{approved} = $f{verdict} =~ /【承認済み】/ ? JSON::PP::true : JSON::PP::false;
  my (%seen, @c);
  for my $s ($f{commit} =~ /\b([0-9a-f]{7,40})\b/g) { push @c, $s unless $seen{$s}++ }
  $f{commits} = \@c;
  my (%url, %dseen, @d);
  while ($f{thread} =~ /\((https?:\/\/[^)\s]*#discussion_r(\d+))\)/g) { $url{$2} = $1 }
  for my $d ($f{thread} =~ /discussion_r(\d+)/g) { push @d, $d + 0 unless $dseen{$d}++ }
  $f{dbids} = \@d;
  $f{links} = [map { exists $url{$_} ? "[r$_]($url{$_})" : "r$_" } @d];
  $f{no_thread} = $f{thread} =~ /^なし/ ? JSON::PP::true : JSON::PP::false;
  my (%rurl, %rseen, @rl);
  while ($f{thread} =~ /\((https?:\/\/[^)\s]*#pullrequestreview-(\d+))\)/g) { $rurl{$2} = $1 }
  for my $r ($f{thread} =~ /pullrequestreview-(\d+)/g) { push @rl, (exists $rurl{$r} ? "[review $r]($rurl{$r})" : "review $r") unless $rseen{$r}++ }
  $f{review_links} = \@rl;
  push @findings, \%f;
  undef $cur;
  undef $lab;
}
while (my $l = <$fh>) {
  $ln++;
  chomp $l;
  $l =~ s/\r$//;
  my $vis = $l;
  my $is_fence = 0;
  if ($l =~ /^\s*(`{3,}|~{3,})(.*)$/) {
    my ($delim, $rest) = ($1, $2);
    if (!$infence) {
      ($is_fence, $infence, $fchar, $flen) = (1, 1, substr($delim, 0, 1), length $delim);
    } elsif (substr($delim, 0, 1) eq $fchar && length($delim) >= $flen && $rest =~ /^\s*$/) {
      ($is_fence, $infence) = (1, 0);
    }
  }
  if (!$is_fence && !$infence) {
    if ($incomment) { if ($vis =~ s/^.*?-->//) { $incomment = 0 } else { $vis = '' } }
    $vis =~ s/<!--.*?-->//g;
    if ($vis =~ s/<!--.*$//) { $incomment = 1 }
  }
  push @todo, $ln if $vis =~ /TODO\(記入\)/;
  my $struct = !$infence && !$is_fence;
  next if $struct && $l =~ /\S/ && $vis !~ /\S/;
  if ($struct && $vis =~ /^#{1,6}\s*\[[^\]]*\]\s*\[/ && $vis !~ /^### \[/) { push @bad, $ln; next }
  if ($struct && $vis =~ /^# /) {
    $title = $vis unless $title;
    $round = $1 + 0 if !defined $round && $vis =~ /^# ゲートラウンド G(\d+)/;
    flush();
    $section = 'header';
    next;
  }
  if ($struct && $vis =~ /^## (.*)$/) {
    flush();
    my $h = $1;
    $section = $h =~ /^指摘/ ? 'findings' : $h =~ /^検証/ ? 'verify' : 'other';
    $has_section = 1 if $section eq 'findings';
    next;
  }
  if ($section eq 'header') {
    push @header, $vis if $vis =~ /\S/;
    if ($struct && $vis =~ /^- PR: #([0-9]+)/) {
      $pr_count++;
      if (!defined $pr_number) {
        ($pr_number, $pr_line) = ($1 + 0, $ln);
        $target_head = $1 if $vis =~ /対象 HEAD:\s*([0-9a-f]{7,40})(?=\s|\z|[（(])/;
      }
    }
    if ($struct && $vis =~ /^- 承認(?::|：)\s*(.*)$/) {
      $approval_count++;
      ($approval_raw, $approval_line) = (trim($1), $ln) if !defined $approval_raw;
    }
    next;
  }
  if ($section eq 'verify') { push @verify, $vis if $vis =~ /\S/; next }
  next unless $section eq 'findings';
  if ($struct && $vis =~ /^### (.*)$/) {
    flush();
    my $rest = $1;
    if ($rest =~ /^\[([A-Za-z0-9._-]+)\]\[([^\]]*)\]\[([^\]]*)\]\s*$/) {
      $cur = { id => $1, bot => $2, where => $3, line => $ln, summary => '', verdict => '', action => '', commit => '', thread => '' };
    } else {
      push @bad, $ln;
    }
    next;
  }
  if ($struct && !$cur && $vis =~ /^指摘なし$boundary\s*(?::|：)?\s*(.*)$/) {
    $no_findings = 1;
    $no_findings_text = trim($1);
    next;
  }
  next unless $cur;
  if ($struct && $vis =~ /^(?:\*\*)?(要旨|判定|対応|コミット|スレッド)(?:\*\*)?(?::|：)(?:\*\*)?\s*(.*)$/) {
    $lab = $label{$1};
    $cur->{$lab} = $2;
    next;
  }
  if (defined $lab) { $cur->{$lab} .= "\n$vis" }
  elsif ($vis =~ /\S/) { $cur->{summary} .= ($cur->{summary} ne '' ? "\n" : '') . $vis }
}
flush();
my $unterminated = $incomment ? 'comment' : $infence ? 'fence' : '';
# 承認の種別: '' = 行なし / 'time' = UTC 時刻（epoch を添える）/ 'auto' = auto-commit / 'bad' = 読めない書式（存在しない日付を含む）。
my ($approval, $approval_epoch) = ('', undef);
if (defined $approval_raw) {
  # 数字は [0-9]（`use utf8` 下の \d は全角数字にも一致する）、年は 2000〜2099 だけ（timegm は 0〜999 年を世紀補正するので、`0126` が 2026 として
  # 通ってしまう）、値の直後は行末か、同じ種類の括弧（全角 `（…）` か半角 `(…)`）で行末に閉じる補足（`approve` が書く形）だけを許す（`…Zjunk`・`…Z-junk`・`…Z (note)TRAIL` を通さない）。読めなければ bad に倒す。
  if ($approval_raw =~ /^auto-commit(?=\z|\s*(?:（.*）|\(.*\))\z)/) {
    $approval = 'auto';
  } elsif ($approval_raw =~ /^(20[0-9]{2})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})Z(?=\z|\s*(?:（.*）|\(.*\))\z)/) {
    # timegm は範囲外の値（13 月など）で die するので eval で受けて bad に倒す。
    $approval_epoch = eval { timegm($6 + 0, $5 + 0, $4 + 0, $3 + 0, $2 - 1, $1 + 0) };
    $approval = defined $approval_epoch ? 'time' : 'bad';
  } else {
    $approval = 'bad';
  }
}
print JSON::PP->new->utf8->canonical->encode({
  approval => $approval, approval_raw => defined $approval_raw ? $approval_raw : '', approval_epoch => $approval_epoch,
  approval_line => $approval_line, approval_count => $approval_count, pr_line => $pr_line, pr_count => $pr_count, target_head => defined $target_head ? $target_head : '',
  title => $title, pr_number => $pr_number, round => $round, header => \@header, verify => \@verify, findings => \@findings,
  bad_headings => \@bad, todo_lines => \@todo, unterminated => $unterminated,
  no_findings => $no_findings ? JSON::PP::true : JSON::PP::false, no_findings_text => $no_findings_text,
  has_findings_section => $has_section ? JSON::PP::true : JSON::PP::false,
});
