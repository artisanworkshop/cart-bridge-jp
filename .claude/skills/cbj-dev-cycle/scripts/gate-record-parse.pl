#!/usr/bin/env perl
# gate-record.sh が使う G<n>.md のパーサー。記録を JSON にして標準出力へ出す（日本語のラベルを扱うので awk ではなく UTF-8 で読む）。
# 使い方: gate-record-parse.pl <G<n>.md>   （直接呼ぶ必要はない）
# 値は HTML コメントを除いた後の文字列から取る（描画されない語で判定や承認が決まらないように）。コードフェンス（``` / ~~~）の
# 中は見出し・ラベルとして解釈しない。閉じていないコメント／フェンスは以降を黙って飲み込むので、unterminated として報告する。
use strict;
use warnings;
use utf8;
use JSON::PP;
my $file = shift @ARGV;
open(my $fh, '<:encoding(UTF-8)', $file) or do { print STDERR "cannot read $file: $!\n"; exit 3 };
my %label = ('要旨' => 'summary', '判定' => 'verdict', '対応' => 'action', 'コミット' => 'commit', 'スレッド' => 'thread');
my (@header, @verify, @findings, @bad, @todo);
my ($title, $section, $cur, $lab, $incomment, $infence, $ln) = ('', '', undef, undef, 0, 0, 0);
my $has_section = 0;
# 判定語の直後に漢字・かな・長音が続く場合（修正不要・保留中・修正しない）は別の語なので判定語として認めない。
my $boundary = qr/(?![\p{Han}\p{Hiragana}\p{Katakana}\x{30FC}])/;
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
  $f{dbids} = [map { $_ + 0 } ($f{thread} =~ /discussion_r(\d+)/g)];
  push @findings, \%f;
  undef $cur;
  undef $lab;
}
while (my $l = <$fh>) {
  $ln++;
  chomp $l;
  $l =~ s/\r$//;
  my $vis = $l;
  my $is_fence = $l =~ /^\s*(?:```|~~~)/ ? 1 : 0;
  if ($is_fence) {
    $infence = $infence ? 0 : 1;
  } elsif (!$infence) {
    if ($incomment) { if ($vis =~ s/^.*?-->//) { $incomment = 0 } else { $vis = '' } }
    $vis =~ s/<!--.*?-->//g;
    if ($vis =~ s/<!--.*$//) { $incomment = 1 }
  }
  push @todo, $ln if $vis =~ /TODO\(記入\)/;
  my $struct = !$infence && !$is_fence;
  next if $struct && $l =~ /\S/ && $vis !~ /\S/;
  if ($struct && $vis =~ /^# /) { $title = $vis unless $title; flush(); $section = 'header'; next }
  if ($struct && $vis =~ /^## (.*)$/) {
    flush();
    my $h = $1;
    $section = $h =~ /^指摘/ ? 'findings' : $h =~ /^検証/ ? 'verify' : 'other';
    $has_section = 1 if $section eq 'findings';
    next;
  }
  if ($section eq 'header') { push @header, $vis if $vis =~ /\S/; next }
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
print JSON::PP->new->utf8->canonical->encode({
  title => $title, header => \@header, verify => \@verify, findings => \@findings,
  bad_headings => \@bad, todo_lines => \@todo, unterminated => $unterminated,
  has_findings_section => $has_section ? JSON::PP::true : JSON::PP::false,
});
