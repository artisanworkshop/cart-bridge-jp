# 検討メモ: 開発者ホスト型 OAuth 中継サーバー（案B「かんたん接続」）

| 項目 | 値 |
|---|---|
| ステータス | **v2.0 の検討事項（未確定）**。2026-09-06 に「v1.0 は現行の案A（BYOアプリ方式）で進め、中継サーバー（案B）は v2.0 で採否を判断する」と決めた。設計判断（D番号）としては未採番で、`docs/03-design-decisions.md` の確定事項ではない。03 と矛盾する記述があれば 03 が優先 |
| 調査日 | **2026-09-06** |
| 調査時点の内部バージョン | プラグイン 0.1.0（`cart-bridge-jp.php`）/ `docs/03` 最終更新 2026-09-05 / `docs/10-tasks.md` Phase 1 進行中 |
| 対象リリース | **v2.0**（Phase 4 着手時に採否を判断。タスク `B4-7`）。採用する場合はカラーミーと BASE の両方を中継で扱う。v1.0 は現行方式のまま。v3.0（MakeShop）は対象外（§2.3） |
| 関連ドキュメント | `docs/01-plan-colorme.md` §OAuth接続フロー / `docs/03-design-decisions.md` §OAuth コールバック・§10（D14） / `docs/04-plan-base.md` §OAuth接続フロー / `docs/02-plan-makeshop.md` §1 |

> **このメモを参照する前に必ず読むこと**
>
> 本メモに書いた外部サービス（カラーミーショップ・BASE・MakeShop・Pressable・各クラウド）の仕様・料金・規約は
> **すべて 2026-09-06 時点の公開情報**である。カラーミー／MakeShop／Pressable の各ドキュメントには
> バージョン番号が無く、日付で管理するしかない（BASE API利用規約のみ「改定日 2020-02-12」の記載あり。
> BASE API 自体は「β版」明記）。外部仕様は予告なく変わるため、**本メモを根拠に設計・実装へ入る前に
> §9「再確認チェックリスト」を実施し、変更があれば本メモを更新してから着手すること。**
> 各節の表にある「確認日」は、その行の根拠を確認した日付である。

---

## 1. 目的と結論

### 1.1 解決したい課題

現行の接続方式（`docs/01-plan-colorme.md` §OAuth接続フロー、実装済み F1-2）は、ショップオーナー自身が
カラーミーショップ デベロッパーに登録し、アプリを作成し、リダイレクトURIを登録し、client_id / client_secret を
プラグインに入力する必要がある。以下「**案A（BYOアプリ方式）**」と呼ぶ。開発者でない利用者には手順が重く、
無料版で「自分のショップのデータで挙動確認」してもらう（D14）以前に脱落するリスクがある。

### 1.2 検討した案

| 案 | 内容 | 本メモでの扱い |
|---|---|---|
| 案A | 現行の BYOアプリ方式＋手順書強化 | **残す**（上級者向け・中継障害時のフォールバック） |
| **案B** | 開発者が登録した **1つのプライベートアプリ** ＋ 開発者がホストする **OAuth中継サーバー**（Google Site Kit の proxy 方式と同型） | **本メモの主題。デフォルトの接続方式にする案** |
| 案C | カラーミー **アプリストアに公開**＋中継サーバー | 対象外。比較用に §2.1.3 に要件だけ記録 |

### 1.3 結論（2026-09-06 時点の見立て）

**判断（2026-09-06）**: v1.0 は現行の案A で公開する。案B は **v2.0 の検討事項**とし、Phase 4 の冒頭（`docs/10-tasks.md` B4-7）で
§7 の未確定事項を潰したうえで採否を決める。採用する場合は v2.0 でカラーミー・BASE の両方を「かんたん接続」に切り替える。
以下は調査時点の見立てで、v2.0 着手時に §9 の再確認を経て見直す。

- 案Bは **カラーミーの制度上「プライベートアプリ」のまま成立する見込み**。アプリストア公開・審査・手数料は不要。
  ただし「同一プライベートアプリを複数ショップが認可できるか」は未実測（§7 要検証 #1）。
- 中継サーバーは **接続時の数秒間だけ**必要（カラーミーのトークンは無期限でリフレッシュ不要）。トラフィックは
  接続1回あたり10リクエスト未満で、どの従量課金基盤でも無料枠に収まる（§5）。
- **Pressable 上の `wc.artws.info`（Pro販売用WordPress）への同居は可能**と判断（§2.4）。条件はページキャッシュの
  明示的除外・チケット保存に transient を使わないこと・秘密情報の保管方法の確認。
- **BASE（v2.0）は中継の効果が最も大きい**。BASEはリフレッシュにも client_secret が必須で、案Aでは
  利用者にアプリ登録を強いる理由がまさにこれ。中継にリフレッシュ代行を持たせれば利用者のアプリ登録が不要になる（§3.4）。
- **MakeShop（v3.0）は中継を作っても利用者の手続きが減らない**。永続トークンはショップ側の「API自社利用登録」で
  MakeShop が発行するもので、第三者アプリ化すると審査・月額費用・脆弱性診断が必要になる。v3.0 は現行計画
  （永続トークン手入力）を維持する（§2.3）。

---

## 2. 調査結果（2026-09-06 時点）

### 2.1 カラーミーショップ

#### 2.1.1 プライベートアプリの制度（案Bの前提）

| 確認事項 | 結果 | 出典 | 確認日 |
|---|---|---|---|
| プライベートアプリの登録項目 | **アプリ名とリダイレクトURIのみ**。「いずれも登録後も変更可能」。特定ショップに紐づける項目は無い | [アプリの種類](https://developer-docs.shop-pro.jp/document/applications/) | 2026-09-06 |
| 認可の主体 | 「OAuth2.0 Authorization Code Grant に従ってショップオーナーから認可を得た上でAPIアクセストークンを発行」。認可画面でショップオーナーがログインする | 同上 / [APIドキュメント](https://developer.shop-pro.jp/docs/colorme-api) | 2026-09-06 |
| 複数ショップでの認可 | ドキュメント上の**禁止・制限記述なし**（明示的な許可記述もなし）。→ 実測と問い合わせで確定（§7 #1） | 同上 | 2026-09-06 |
| リダイレクトURIの数 | 登録画面は単一フィールドと読める。複数登録・ワイルドカードの記述なし。→ 中継は**固定の1URI**で設計する（§3） | [アプリの種類](https://developer-docs.shop-pro.jp/document/applications/) / [naeco.jp 準備編](https://naeco.jp/colorme-shop-api) | 2026-09-06 |
| リダイレクトURIの https 要否 | プライベートアプリは **http の localhost でも自動リダイレクト可**（要検証#7 実機確認 2026-09-03） | `docs/03` §9 #7 | 2026-09-03 |
| トークン寿命 | **無期限**（リフレッシュ不要） | `docs/01` §1 | 2026-09-05 |
| 利用料 | プライベートアプリは「無料でご利用いただけます」 | [アプリの種類](https://developer-docs.shop-pro.jp/document/applications/) | 2026-09-06 |
| 認可コード | 有効期限10分・交換1回のみ | `docs/01` §1 | 2026-09-05 |

#### 2.1.2 API利用規約（案Bに関係する条文）

| 条文 | 内容（要旨） | 案Bへの影響 | 出典 | 確認日 |
|---|---|---|---|---|
| 第3条（アクセスキー） | 「発行を受けたアクセスキーの管理責任を負う」「第三者に譲渡・貸与・開示してはならない」 | client_secret を利用者に配布せず開発者側で保管する案Bは、この条文の趣旨に**沿う**（案Aは各利用者が自分のキーを管理） | [API利用規約](https://api.shop-pro.jp/developers/tos) | 2026-09-06 |
| 第4条（利用許諾） | 「非独占的かつ再許諾不可」 | APIそのものの再許諾の話。ショップオーナーが開発者のアプリを認可する行為は再許諾ではない、と解釈しているが**未確認**（§7 #2） | 同上 | 2026-09-06 |
| 第8条（禁止事項） | 「当社による許諾を得ることなく、本件API又はそのライセンスを販売、賃貸、再許諾する行為」 | 同上 | 同上 | 2026-09-06 |
| プライベートアプリの第三者利用 | **該当条文なし** | — | 同上 | 2026-09-06 |

#### 2.1.3 参考: アプリストア公開（案C）の要件

案Cは採らないが、将来「集客のためにストアへ載せたい」となった場合の判断材料として記録する。

| 項目 | 内容 | 出典 | 確認日 |
|---|---|---|---|
| モード切替 | プライベートアプリを「アプリストアモード」に切替。**戻せない・削除できない・既存トークンは無効化** | [アプリの種類](https://developer-docs.shop-pro.jp/document/applications/) | 2026-09-06 |
| 連携方法 | 「アプリストア アプリのショップとの連携は、プライベート アプリとショップとの連携方法と異なります」→ **ストアから追加 → インストールフック → redirect_url → 認可** の順序固定。プラグイン起点の認可は使えない可能性が高い | 同上 / [インストール](https://developer-docs.shop-pro.jp/document/installation/) | 2026-09-06 |
| フック | インストール／アンインストールフック（POST JSON、`X-Appstore-Signature` HMAC-SHA256 Base64、200 + `redirect_url` 必須。失敗時はインストール中止。アンインストールは2時間30分ごと最大19回再送） | [インストール](https://developer-docs.shop-pro.jp/document/installation/) / [アンインストール](https://developer-docs.shop-pro.jp/document/uninstallation/) | 2026-09-06 |
| 最低技術要件 | 開発者ドメイン上の設定ページURL、ログアウト機能、ショップ名またはアカウントIDの表示 | [最低技術要件](https://developer-docs.shop-pro.jp/document/technical-requirements/) | 2026-09-06 |
| 課金形式 | 無料 / 買い切り / 月額 / 月額+初期費用 / 月額+従量 / 従量 の6種。**「無料アプリでの自社課金は禁止」**（課金が発生するならストアで有料プラン設定が必須）。プラン変更機能なし | [FAQ](https://developer-docs.shop-pro.jp/document/faq/) / [開発者向け資料PDF](https://app.shop-pro.jp/appstore_document_for_developer.pdf) | 2026-09-06 |
| 手数料 | 率は非公開（「お問い合わせください」）。最低振込額 3,000円、振込手数料 264円、有料アプリは毎月末振込 | 同PDF / [手数料計算と振込](https://developer-docs.shop-pro.jp/document/billing/transfer.html) | 2026-09-06 |
| 審査期間 | 通常5〜10営業日（ページにより記載が異なる） | [審査ガイドライン](https://developer-docs.shop-pro.jp/guideline/judging/) / [開発の流れ](https://developer.shop-pro.jp/getting-started/appstore-developmemt_flow) | 2026-09-06 |

### 2.2 BASE（v2.0 で中継を拡張する前提の調査）

| 確認事項 | 結果 | 出典 | 確認日 |
|---|---|---|---|
| アプリ登録・費用 | BASE Developers で申請。「無料で申請や利用が可能」「登録が完了したあと、即時利用可能」 | [利用は無料ですか](https://help.thebase.in/hc/ja/articles/9811104854937) / [どのくらいで利用開始](https://help.thebase.in/hc/ja/articles/9811103840409) | 2026-09-06 |
| 非公開アプリ | 「非公開のアプリケーションでも、利用や申請が可能です」 | [非公開でも申請できますか](https://help.thebase.in/hc/ja/articles/9811161939993) | 2026-09-06 |
| 公式Apps掲載 | 「現在、BASEの公式Appsへの申請や公募は、おこなっておりません」→ ストア公開という選択肢自体が無い | [公式Appsとして掲載](https://help.thebase.in/hc/ja/articles/9811165637529) | 2026-09-06 |
| 複数ショップでの認可 | 制限記述なし。BASE連携の外部SaaSが一般に「利用者はBASEにログインして認可するだけ」で成立している運用と整合するが、**未実測**（§7 #8） | [OAuth authorize](https://docs.thebase.in/api/oauth/authorize/) | 2026-09-06 |
| リフレッシュ | `POST /1/oauth/token` に **client_id・client_secret・redirect_uri・refresh_token が必須**（Basic認証でも可）。アクセストークン1時間、リフレッシュトークン「30日程度」、**ローテーション式** | [refresh_token](https://docs.thebase.in/api/oauth/refresh_token/) | 2026-09-06 |
| コールバックURL | 「登録したコールバックURL」との一致が必須。https要否・複数登録可否の記述なし（`docs/03` 要検証#9 のまま） | [OAuth authorize](https://docs.thebase.in/api/oauth/authorize/) | 2026-09-06 |
| WordPressプラグイン | BASE側のサポート無し（「プラグイン配布元へお問い合わせください」） | [WordPressプラグイン利用](https://help.thebase.in/hc/ja/articles/9811105818777) | 2026-09-06 |
| 規約（改定 2020-02-12） | 第3条 ID/パスワードの管理義務・第三者利用禁止。第5条1項「再許諾、貸与その他の処分をしてはならない」。**第8条1号「本API又は本情報を当社のサービスと競合するサービスのために使用する行為」を禁止** | [API利用規約](https://thebase.com/pages/api_term) | 2026-09-06 |

**判定**: 中継の価値が最も高い。案Aで利用者にアプリ登録をさせている理由は「リフレッシュに client_secret が要る」ことなので、
中継にリフレッシュ代行を持たせれば利用者側の登録が不要になる。反面、中継が**移行期間中ずっと**稼働している必要がある（§3.4）。
規約第8条1号（競合サービス）は中継の有無に関係なく v2.0 全体のリスクなので、v2.0 着手前に問い合わせる（§7 #9）。

### 2.3 MakeShop（v3.0。中継の対象外とする根拠）

| 確認事項 | 結果 | 出典 | 確認日 |
|---|---|---|---|
| 自社利用の申請経路 | 2026-07-06 から新管理画面「アプリ管理 / API利用設定」で申請。**全プラン対象、主管理者のみ**。審査通過後に管理画面で認証情報を確認でき、情報種別の追加・削除も自身で可能。費用は記事に明記なし | [makeshopマガジン 2026-07-06](https://www.magazine.makeshop.jp/api-usage-settings/) | 2026-09-06 |
| 手続き期間 | 「API自社利用申請から手続き完了までは約5営業日」 | [API自社利用登録](https://developers.makeshop.jp/signup/shopowner)（アクセス制限あり。検索結果の要約に依拠） | 2026-09-06 |
| トークン | 永続トークン。第三者アプリの場合は**インストールWebhookで永続トークンが通知**される（`x-makeshop-signature` HMAC-SHA256 + `x-makeshop-request-timestamp`） | [Webhook](https://developers.makeshop.jp/guide/webhook.html) / [利用登録](https://developers.makeshop.jp/signup/) | 2026-09-06 |
| 第三者アプリの公開区分 | **一般公開**: 全ショップ対象、利用料「決済金額の10%（税抜）」、Webhook 4種必須。**限定公開**: 指定ショップIDのみ、「月額1,000円（税抜）/ 限定公開ショップ数」、Webhook 2種必須 | [FAQ](https://developers.makeshop.jp/faq/) / [開発ガイド](https://developers.makeshop.jp/guide/) | 2026-09-06 |
| 期間・審査 | apps developer登録（即時）→ アプリ開発申請（約5営業日）→ 公開申請（約10〜15営業日。ページにより異なる）→ 審査 | [利用登録](https://developers.makeshop.jp/signup/) / [開発ガイド](https://developers.makeshop.jp/guide/) | 2026-09-06 |
| 脆弱性診断 | 利用規約 第10条1項に基づき「脆弱性診断の実施をお願い」（有償の提携プランを案内） | [利用登録](https://developers.makeshop.jp/signup/) | 2026-09-06 |
| SSO | redirect_uri は **https 必須**。一時トークン5分・リフレッシュ12時間・PKCE対応 | [要件・仕様](https://developers.makeshop.jp/guide/specifications.html) | 2026-09-06 |

**判定**: 中継を作っても、利用者が MakeShop 管理画面で「API利用設定」を申請して約5営業日待つ工程は消えない
（トークンを発行するのは MakeShop）。第三者アプリとして中継で受ければ利用者の申請は不要になるが、限定公開でも
利用ショップ数×月額1,000円、審査、脆弱性診断が乗る。**v3.0 は `docs/02` の現行計画（永続トークン手入力）を維持**し、
将来需要があれば限定公開アプリ化を別途検討する。その場合、§3 の中継にWebhook受信口を足す形で流用できる。

### 2.4 Pressable（`wc.artws.info` への同居可否）

Pressable は Automattic 系のマネージドWordPressホスティング（WP Cloud）。「任意のPHPを動かす汎用サーバー」ではないが、
WordPressプラグインとして実装する中継なら動かせる。

| 確認事項 | 結果 | 出典 | 確認日 |
|---|---|---|---|
| 自作プラグイン・mu-plugins | 利用可。GitHub連携で `plugins/`・`mu-plugins/` を同期できる。SFTP あり（コア「Managed WordPress」ファイルは不可視） | [GitHub連携 mu-plugins](https://pressable.com/changelog/github-integration-update-now-syncing-must-use-mu-plugins/) / [プラットフォーム考慮事項](https://pressable.com/knowledgebase/service-platform-considerations/) | 2026-09-06 |
| 禁止プラグイン | キャッシュ系（W3 Total Cache 等。`advanced-cache.php` 書込不可）、ミニファイ、メール一括送信、Broken Link Checker 等。**REST API を追加する類のプラグインは対象外** | [Disallowed plugins](https://pressable.com/knowledgebase/disallowed-plugins/) | 2026-09-06 |
| アウトバウンド通信 | Egress Firewall 既定で **TCP 80/443 許可**（他は拒否・記録）。`api.shop-pro.jp` / `api.thebase.in` への HTTPS は問題なし | [プラットフォーム考慮事項](https://pressable.com/knowledgebase/service-platform-considerations/) | 2026-09-06 |
| ページキャッシュ（Batcache） | 同一URLに2分以内に2回アクセスで2回目からキャッシュ、TTL 5分。`wp`/`wordpress` 接頭辞Cookieがあると対象外。`batcache_cancel()` で個別除外可。ヒット時は `x-nananana` ヘッダー | [Batcacheの仕組み](https://pressable.com/knowledgebase/how-does-batcache-page-caching-work/) / [除外方法](https://pressable.com/knowledgebase/how-to-prevent-batcache-page-caching-on-pressable/) | 2026-09-06 |
| オブジェクトキャッシュ | Memcached による永続オブジェクトキャッシュが既定で有効 → **transient は Memcached に載り、退避されうる**。チケット・state の保存に transient を使わない（§3.6） | [Batcacheの仕組み](https://pressable.com/knowledgebase/how-does-batcache-page-caching-work/) | 2026-09-06 |
| PHP | 8.2 未満は非提供（本プラグインの要件 8.2+ と一致） | [トラブルシューティング](https://pressable.com/knowledgebase/understand-wordpress-errors-a-troubleshooting-guide-for-pressable-sites/) | 2026-09-06 |
| cron | WP-Cron に加え、プラットフォーム管理のサーバーサイドcron（最大実行8時間・同時3つ）。期限切れチケットの掃除に使える | [Cron jobs](https://pressable.com/knowledgebase/how-to-manage-cron-jobs-at-pressable/) | 2026-09-06 |
| 稼働率 | 「100% uptime guarantee」（計画メンテ除く） | [100% Uptime](https://pressable.com/features/manage/100-percent-uptime-guarantee/) | 2026-09-06 |
| 追加費用 | 既存サイトへの同居なので **0円**（プラン上限のトラフィック・PHPワーカーを消費するが、想定量は無視できる） | — | 2026-09-06 |

**判定**: 同居は可能。以下は着手時に実測で潰す（§7 #4〜#6）。

- `/wp-json/` の GET が Batcache・Edge Cache の対象になるか（中継の入口は固有クエリ付き・コールバックは固有 code 付きなので
  実害は出にくいが、`batcache_cancel()` と `Cache-Control: no-store, private` を必ず両方入れる）。
- `wp-config.php` に定数を追加できるか。できなければ client_secret は `TokenStore` と同じ方式（WPソルト由来鍵で暗号化してオプション保存）にする。
- ドメインエイリアス（例: `connect.artws.info`）を同一サイトに追加できるか。追加できれば将来の移設時にリダイレクトURIを変えずに済む。

### 2.5 参考: 専用サーバーレス基盤（Pressable以外を選ぶ場合）

| 基盤 | 無料枠（2026-09-06時点） | 超過単価 | 備考 |
|---|---|---|---|
| Cloudflare Workers + KV | 10万req/日、CPU 10ms/req。KV 読10万/日・書1,000/日 | 有料 $5/月（1,000万req・CPU 3,000万ms込み、超過 $0.30/100万req） | TypeScript。コールドスタート無し。独自ドメイン無料。出典: [Workers pricing](https://developers.cloudflare.com/workers/platform/pricing/) |
| Google Cloud Run（リクエストベース課金）+ Firestore | 200万req/月、18万vCPU秒、36万GiB秒 | $0.40/100万req、$0.000018/vCPU秒、$0.000002/GiB秒（2026-08時点の既定単価） | インスタンス0で待機料なし。独自ドメインはドメインマッピングか Cloudflare DNS 前段。ロードバランサは月約$18かかるので使わない。出典: [Cloud Run pricing](https://cloud.google.com/run/pricing) |
| AWS Lambda（Function URL）+ DynamoDB | 100万req/月、40万GB秒（永続無料枠） | $0.20/100万req、$0.0000166667/GB秒 | IAM設定がやや重い。出典: [Lambda pricing](https://aws.amazon.com/lambda/pricing/) |
| Vercel | — | — | Hobby プランは商用利用不可のため候補外 |

---

## 3. 案Bのアーキテクチャ

### 3.1 構成要素

```
[利用者のWordPress]                      [中継サーバー wc.artws.info]              [カラーミーショップ]
 cart-bridge-jp プラグイン                 WordPressプラグイン cbjp-relay              api.shop-pro.jp
  - 接続ボタン（hosted モード）             - /start   入口・確認画面                    /oauth/authorize
  - /connect/{p}/hosted-callback          - /callback 固定リダイレクトURI               /oauth/token
  - TokenStore（既存）                     - /redeem  チケット→トークン引換（S2S）
  - 上級者向け: 既存の BYO モード            - client_secret を暗号化保管
                                          - チケット/state 保存（オプションテーブル）
```

- 中継のURLは `https://wc.artws.info/wp-json/cbjp-relay/v1/...` を第一候補とする。カラーミー側に登録するリダイレクトURIは
  `.../v1/colorme/callback` の**1本**。リダイレクトURIは登録後も変更可能なので、将来 Cloudflare Workers 等へ移設する場合は
  デベロッパー画面で書き換える（発行済みトークンには影響しない）。
- プラグイン側は「かんたん接続（hosted）」を既定にし、既存の BYO 方式を「上級者向け: 自分のアプリを使う」として残す。
  TokenStore の `settings` に `mode: 'hosted' | 'byo'` を持たせて区別する。

### 3.2 接続フロー（カラーミー）

1. 管理画面で「かんたん接続」を押す。プラグインは `handoff_id`（ランダム32文字）を発行し、**管理ユーザーIDに紐づけて10分保存**
   （既存 `ColorMeOAuth::issue_state()` と同じ考え方）。ブラウザを中継の `/v1/colorme/start?site={rest_root}&hid={handoff_id}` へ遷移させる。
   `site` は利用者サイトの REST ルート URL（`rest_url()` の値。例: `https://example.com/wp-json/`）で、以降の手順の `{site}` も同じ意味。
2. 中継は**確認画面**を表示する: 「WordPressサイト `{site}` をカラーミーショップに接続します」。利用者が「続行」を押すと、
   中継は `state`（ランダム）を発行し `{state → site, hid, expires}` を保存してカラーミーの認可URLへ302する。
   認可URLの `redirect_uri` は中継の `/v1/colorme/callback` 固定。
3. 利用者がカラーミーにログインして「連携」を押すと、カラーミーが中継の `/callback?code=...&state=...` へ戻す。
4. 中継は `state` を検証（1回限り・期限内）し、`code` を client_secret でトークンに交換する。
5. 中継は **1回限りのチケット**（ランダム、TTL 5分）を発行して `{ticket → access_token, site, hid}` を保存し、
   ブラウザを `{site}cbjp/v1/connect/colorme/hosted-callback?hid={hid}&ticket={ticket}` へ302する。
   **URLにトークンは載せない。**
6. WordPress側は `hid` が自サイトで発行済み・期限内・同じ管理ユーザーであることを確認し、**サーバー間通信**で中継の
   `/v1/colorme/redeem` に `ticket` と `hid` をPOSTする。中継はトークンを1回だけ返してチケットを削除する。
7. WordPress側は `TokenStore::save()` で保存（`settings.mode = 'hosted'`）し、`GET /v1/shop.json` で接続テストして管理画面へ戻す。

利用者の操作は「ボタンを押す → 確認 → カラーミーにログインして連携」の3ステップになる。手順1と6は既存の
`RestController` の `authorize-url` / `callback` と並ぶ新ルートとして追加する（`docs/03` §REST 一覧に追記が必要）。

### 3.3 中継サーバーのエンドポイント

| パス（`/wp-json/cbjp-relay/v1` 配下） | メソッド | 役割 | 認証・保護 |
|---|---|---|---|
| `/{platform}/start` | GET | 確認画面の表示 | `site` の形式検証（https または http://localhost のみ）、レート制限 |
| `/{platform}/authorize` | POST | state 発行 → ASP 認可URLへ302 | 確認画面からのフォーム（nonce） |
| `/{platform}/callback` | GET | ASP からの戻り。state 検証 → code 交換 → チケット発行 → WPへ302 | state（1回限り・10分） |
| `/{platform}/redeem` | POST | チケット → トークン（1回限り） | チケット（5分）+ `hid` 一致、レート制限 |
| `/base/refresh` | POST | v2.0: リフレッシュ代行（§3.4） | サイト資格情報（`site_id` + `site_secret`） |
| `/health` | GET | 監視用 | なし |

すべてのレスポンスに `Cache-Control: no-store, private` を付け、`batcache_cancel()` を呼ぶ。

### 3.4 BASE への拡張（v2.0）

- BASE は `refresh_token` グラントにも client_secret と登録済み redirect_uri が必須（§2.2）。したがって中継は
  「接続時だけ」でなく**移行ジョブが動いている間ずっと**必要になる（アクセストークン1時間ごとに1回の代行）。
- 中継は接続完了時に WordPress サイトへ `site_id` / `site_secret` を発行し（Site Kit と同型）、`/base/refresh` の呼び出しを
  この資格情報で認証する。中継はトークンを永続化せず、WordPress が保持する refresh_token を受け取って BASE に中継し、
  新しいトークン組を返すだけにする（ローテーション式なので `TokenStore` 側の排他ロック `acquire_refresh_lock()` は従来通り必要）。
- 中継障害時は `BaseClient` がリフレッシュ失敗を「再接続が必要」として扱う既存設計（`docs/04` §OAuth接続フロー 5）に乗せ、
  UIで「上級者向け: 自分のアプリを使う」への切替を案内する。
- 中継のURLは Cloudflare Workers 等へ移設可能な設計にしておく（プラットフォームごとの `/v1/{platform}/...` 名前空間、
  ストレージ抽象化）。BASEの refresh は接続あたり月数百リクエストに増えるが、それでも §5 の無料枠内。

### 3.5 MakeShop

v3.0 では中継を使わない（§2.3）。将来「限定公開アプリ」化する場合は、§3.3 に MakeShop のインストール／アンインストール Webhook
受信口（`x-makeshop-signature` 検証、永続トークンをチケット経由で WordPress へ引き渡し）を追加する。

### 3.6 中継サーバーの実装方針（Pressable 同居版）

- 独立した小さなWordPressプラグイン（例: `cbjp-relay`。本リポジトリとは**別リポジトリ**。無料版プラグインに中継のコードや
  client_secret を含めない。D14 の「Pro固有コードを含めない」と同じ境界）。
- 永続化は **専用テーブル**（`{$wpdb->prefix}cbjp_relay_tickets`: `kind`(state|ticket|site), `key_hash`, `payload`(暗号化), `expires_at`）
  または `add_option(..., autoload='no')`。**transient は使わない**（Pressable の Memcached で退避されうる。§2.4）。
  期限切れ行はサーバーサイドcronで削除する。
- client_secret は `wp-config.php` 定数が使えればそれを優先、使えなければ `TokenStore` と同じ暗号化方式でオプション保存。
- 秘密情報（トークン・code・チケット）をログに書かない。`Logger` 相当を入れる場合はマスクを必須にする。
- 中継のコードにも本リポジトリと同じ `composer lint` / `composer analyze` / PHPUnit を適用する。

### 3.7 プラグイン側（本リポジトリ）の変更点

| 箇所 | 変更 |
|---|---|
| `Adapters\ColorMe\ColorMeOAuth` | hosted モードの `authorize_url()`（中継の `/start` URL生成）と `redeem()`。BYO モードの既存メソッドは維持 |
| `Admin\RestController` | `POST /connections/{platform}/hosted-handoff`（handoff 発行）と `GET /connect/{platform}/hosted-callback`（チケット受領→redeem→保存）。後者は既存 `callback` と同じく permission `__return_true` + handoff 検証 |
| `Support\TokenStore` | `settings.mode` の導入。`save_token_if_credentials_match()` の hosted 版（client_id/secret ではなく `mode` と `hid` の一致で保存）。既存の CAS 方式を踏襲 |
| `Adapters\ConnectionField` / `connection_fields()` | `oauth_button` に `hosted: true` 等の属性を追加し、UI が「かんたん接続」と「上級者向け」を出し分けられるようにする |
| `src/`（React） | 接続画面: 既定は「かんたん接続」ボタン1つ。「上級者向け: 自分のアプリを使う」を折りたたみで残す。接続状態表示は `connected` / `needs_reconnect` の両フラグを見る既存ルールを維持 |
| `readme.txt` | 外部サービス（中継）の利用を明記し、利用規約・プライバシーポリシーのURLを載せる（wordpress.org ガイドライン。§6） |
| `docs/03` | 採用が決まったら D番号を採番し、§REST 一覧・§OAuth コールバック・§9 要検証表を更新。本メモは「採用済み」に書き換えて残す |

`LimitPolicy`（無料版上限）には影響しない。中継はトークン取得の経路であって、インポート経路ではない。

### 3.8 セキュリティ要件

| 脅威 | 対策 |
|---|---|
| CSRF / 認可コード横取り | `state` はランダム・1回限り・10分。`hid` は管理ユーザーIDに紐づけ、hosted-callback で同一ユーザーを検証 |
| **フィッシング（攻撃者が自サイト向けの `/start` リンクを被害者に踏ませ、被害者ショップのトークンを攻撃者サイトへ流す）** | OAuthプロキシ方式に固有のリスク。中継の**確認画面で接続先WordPressサイトのドメインを必ず表示**し、利用者に確認させる。`site` は https（または `http://localhost` 系）のみ許可し、リダイレクト先パスは `/cbjp/v1/connect/{platform}/hosted-callback` に固定する（オープンリダイレクト禁止） |
| トークン漏洩 | URLにトークンを載せない（チケット方式）。チケットは1回限り・5分。中継は redeem 後にトークンを削除し永続化しない。TLS 必須。ログにトークンを書かない |
| 中継の悪用（他人の client_secret で交換させる） | `redeem` はチケット+`hid` 一致が必要。`/start`・`/redeem` に IP レート制限。`/base/refresh` は `site_id`/`site_secret` 認証 |
| client_secret 漏洩 | 中継サーバーだけが保持。漏洩時はカラーミー デベロッパー画面で再発行し中継の設定を差し替える（発行済みトークンは影響なし。要確認 §7 #3） |
| 中継停止 | 新規接続（BASEはリフレッシュも）が止まる。**BYO モードをフォールバックとして常に残す**。`/health` を外形監視 |

### 3.9 運用

- 中継APIは `/v1/` でバージョン付けし、プラグイン側は互換性のない変更に備えて `v` パラメータを送る。
- 障害・仕様変更時の連絡経路として、readme と管理画面に中継のステータスページURLを載せる。
- カラーミー側でアプリ名（認可画面に表示される）を「Cart Bridge JP – Migrate for WooCommerce」など、プラグイン名と一致させる。

---

## 4. サーバー基盤の比較（案Bをどこで動かすか）

| 観点 | Pressable 同居（`wc.artws.info`） | Cloudflare Workers | Cloud Run |
|---|---|---|---|
| 追加費用 | 0円 | 0円（有料化しても $5/月） | 0円 |
| 実装言語 | PHP（本体と同じ。`TokenStore` の暗号化・CAS の知見を流用） | TypeScript（管理画面UIと同じ） | 任意（コンテナ） |
| 運用負荷 | 既存サイトの運用に相乗り。WP本体・プラグイン更新は Pressable 管理 | ほぼゼロ。ただし別スタックの学習 | コンテナビルド・IAM・ドメイン設定 |
| 可用性 | 販売サイトと運命共同体（100% SLA だが自分のプラグイン障害は自己責任） | 高い | 高い |
| キャッシュ・永続化の落とし穴 | Batcache 除外・transient 不使用が必須（§2.4） | KV は結果整合（1回限りチケットの厳密性に注意。Durable Objects で回避可） | Firestore |
| 移設のしやすさ | リダイレクトURIを変えれば移設可（§3.1） | — | — |
| **結論** | **第一候補**。PHP で最短、費用ゼロ、既存資産流用 | 第二候補。トラフィック増や販売サイトとの分離が必要になったら移設 | Google Cloud に慣れているなら可。今回の規模では Workers に対する利点は薄い |

---

## 5. 費用試算（2026-09-06 時点の単価）

想定トラフィック: 接続1回あたり中継リクエスト 6〜10件（start / authorize / callback / redeem + 静的）。
BASE は加えて移行ジョブ稼働中に 1リクエスト/時のリフレッシュ。

| 月間接続数 | カラーミー中継リクエスト | BASE リフレッシュ込み（1接続あたり10日稼働と仮定） | Pressable 同居 | Workers Free | Cloud Run |
|---|---|---|---|---|---|
| 100 | 約1,000 | 約25,000 | 0円 | 0円 | 0円 |
| 1,000 | 約10,000 | 約250,000 | 0円 | 0円 | 0円 |
| 10,000 | 約100,000 | 約2,500,000 | 0円（PHPワーカー要確認） | 0円（10万req/日以内） | 数十円〜数百円 |

固定費はドメイン（既存）のみ。カラーミー側の手数料は案Bでは発生しない（プライベートアプリは無料。§2.1.1）。

---

## 6. 法務・規約・配布上の確認事項

- **wordpress.org プラグインガイドライン**: 外部サービスへ接続するプラグインは readme に「何のために・どのデータを・どこへ送るか」と
  サービスの利用規約・プライバシーポリシーへのリンクを明記する。中継はトークンを一時的に扱うため、プライバシーポリシーに
  「認可コード・アクセストークンを接続完了までの間だけ処理し保存しない」旨を書く。出典: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)（確認日 2026-09-06）。
- **カラーミー API利用規約 第3条**（アクセスキー管理責任）: 中継運営者として管理責任を負う。第4条・第8条（再許諾）の解釈は問い合わせで確定（§7 #2）。
- **BASE API利用規約 第8条1号**（競合サービスのための利用禁止）: WooCommerce への移行ツールが該当するかは v2.0 全体の前提。中継の有無に関係なく問い合わせる（§7 #9）。
- **中継の利用規約**: 中継自体のサービス利用規約（提供範囲・停止・免責）を用意し、プラグインの初回接続時に同意を取る。

---

## 7. 未確定事項（要検証・要問い合わせ）

| # | 事項 | 確定方法 | 影響 |
|---|---|---|---|
| 1 | 同一プライベートアプリを**複数ショップ**が認可できるか | デベロッパー画面でテストショップを2つ作り、同じアプリで両方認可して `shop.json` を叩く | 不可なら案Bは成立せず、案C（アプリストア）に切替 |
| 2 | 規約上、プライベートアプリを第三者ショップに使わせてよいか（第4条・第8条の解釈） | カラーミー デベロッパーサポートへ問い合わせ（文面: 付録A.1） | 不可なら案C |
| 3 | client_secret 再発行時に発行済みトークンが失効するか | 問い合わせまたはテストショップで実測 | 秘密ローテーション手順 |
| 4 | Pressable で `/wp-json/` GET が Batcache / Edge Cache の対象になるか | `x-nananana` ヘッダーの有無を実測 | 除外実装の要否 |
| 5 | Pressable で `wp-config.php` に定数を追加できるか | ダッシュボード／SFTP で確認 | secret 保管方式 |
| 6 | Pressable で同一サイトにドメインエイリアス（`connect.artws.info`）を追加できるか | ダッシュボードで確認 | 将来の移設時にリダイレクトURI変更が不要になる |
| 7 | ColorMe 認可画面に表示されるアプリ名・提供者名の表示仕様 | テストショップで実測（スクリーンショットを手順書に使う） | フィッシング対策の説明文 |
| 8 | BASE: 同一アプリを複数ショップが認可できるか、コールバックURLの https 要否・複数登録可否 | v2.0 B4-0 の実測に統合（`docs/03` 要検証#9） | v2.0 での中継拡張の成立 |
| 9 | BASE 規約第8条1号（競合サービス）に移行ツールが該当するか | BASE API サポートへ問い合わせ（文面: 付録A.2） | v2.0 全体 |
| 10 | MakeShop「API利用設定」の費用（記事に明記なし） | マニュアル（要ログイン相当。Cloudflare の JS チャレンジで自動取得不可）を手動確認 | README の前提条件（`docs/02` 要確認#3 と同じ） |

---

## 8. v2.0 で採用する場合の段階案（タスク化は `docs/10-tasks.md` で行う。ここでは粒度の目安のみ）

v1.0 では着手しない。Phase 4 の B4-7（採否判断）で §7 を確定し、採用なら以下を Phase 4〜5 のタスクに展開する。

| 段階 | 内容 | 成果物 |
|---|---|---|
| R0（= B4-7） | §9 の再確認 → §7 #1〜#9 の実測・問い合わせ（付録A）。結果を本メモ §2 に追記し採否を決める | 本メモ更新、`docs/03` 要検証表への転記、採否の記録 |
| R1 | 中継プラグイン MVP（§3.3 の start/authorize/callback/redeem/health、専用テーブル、確認画面、レート制限、テスト）。カラーミーと BASE（`/base/refresh`、`site_id`/`site_secret`。§3.4）を最初から対象にする | 別リポジトリ `cbjp-relay`、`wc.artws.info` へ配置 |
| R2 | 本体プラグインの hosted モード（§3.7）。BYO モードは維持。E2E: カラーミー・BASE の実テストショップで hosted 接続 → dry-run | PR（`/start-task`） |
| R3 | readme.txt の外部サービス開示、プライバシーポリシー、中継の利用規約、手順書のスクリーンショット | ドキュメント |
| R4 | `docs/03` に設計判断として採番（D19 以降）、`docs/01` / `docs/04` の §OAuth接続フロー更新、本メモを「採用済み」へ | ドキュメント |

---

## 9. 再確認チェックリスト（本メモを参照して作業する前に実施）

各項目の URL を開き、**確認日の記述と変わっていないか**を確認する。変わっていれば本メモの該当行と「改訂履歴」を更新してから作業に入る。

- [ ] カラーミー [アプリの種類](https://developer-docs.shop-pro.jp/document/applications/): プライベートアプリの登録項目（アプリ名・リダイレクトURIのみ）、無料、切替時の注意（2026-09-06）
- [ ] カラーミー [API利用規約](https://api.shop-pro.jp/developers/tos): 第3条・第4条・第8条の文言（2026-09-06）
- [ ] カラーミー [APIドキュメント](https://developer.shop-pro.jp/docs/colorme-api): 認可URL・トークンURL・トークン無期限・認可コード10分（2026-09-06）
- [ ] カラーミー [FAQ](https://developer-docs.shop-pro.jp/document/faq/): 「無料アプリでの自社課金禁止」「アプリ削除不可」（案Cへ切替える場合のみ。2026-09-06）
- [ ] BASE [refresh_token](https://docs.thebase.in/api/oauth/refresh_token/): client_secret・redirect_uri 必須、ローテーション式、期限（2026-09-06）
- [ ] BASE [API利用規約](https://thebase.com/pages/api_term): 改定日 2020-02-12、第5条・第8条1号（2026-09-06）
- [ ] BASE ヘルプ: [無料](https://help.thebase.in/hc/ja/articles/9811104854937) / [即時利用](https://help.thebase.in/hc/ja/articles/9811103840409) / [非公開アプリ可](https://help.thebase.in/hc/ja/articles/9811161939993) / [公式Apps募集なし](https://help.thebase.in/hc/ja/articles/9811165637529)（2026-09-06）
- [ ] MakeShop [FAQ](https://developers.makeshop.jp/faq/) / [開発ガイド](https://developers.makeshop.jp/guide/): 公開区分と利用料（一般公開 決済金額の10%、限定公開 月額1,000円/ショップ）、[マガジン 2026-07-06](https://www.magazine.makeshop.jp/api-usage-settings/) の「API利用設定」（2026-09-06）
- [ ] Pressable: [Batcache](https://pressable.com/knowledgebase/how-does-batcache-page-caching-work/) / [除外方法](https://pressable.com/knowledgebase/how-to-prevent-batcache-page-caching-on-pressable/) / [プラットフォーム考慮事項](https://pressable.com/knowledgebase/service-platform-considerations/)（Egress 80/443）/ [禁止プラグイン](https://pressable.com/knowledgebase/disallowed-plugins/) / [Cron](https://pressable.com/knowledgebase/how-to-manage-cron-jobs-at-pressable/)（2026-09-06）
- [ ] クラウド料金（Pressable 以外を選ぶ場合のみ）: [Cloudflare Workers](https://developers.cloudflare.com/workers/platform/pricing/) / [Cloud Run](https://cloud.google.com/run/pricing) / [AWS Lambda](https://aws.amazon.com/lambda/pricing/)（2026-09-06）
- [ ] wordpress.org [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/): 外部サービス開示の要件（2026-09-06）
- [ ] 本リポジトリ側: `docs/03` の §OAuth コールバック・§REST 一覧・要検証表に、本メモ作成後の変更が入っていないか（`git log -- docs/03-design-decisions.md`）

---

## 付録A. 問い合わせ文面（下書き・2026-09-06）

§7 の #2・#3・#7（カラーミー）と #8・#9（BASE）を確定するための文面。送付前に【 】内を埋め、
送付日・回答内容・回答日を §2 の該当行と改訂履歴に反映すること。

### A.1 カラーミーショップ デベロッパーサポート宛

送付先: デベロッパーサイトの問い合わせフォーム（ https://help.shop-pro.jp/hc/ja/requests/new?ticket_form_id=239947 ）。
件名案: 「プライベートアプリを複数ショップに認可いただく提供形態についての確認」

```
カラーミーショップ デベロッパーサポート ご担当者様

お世話になっております。【会社名】の【氏名】と申します（デベロッパーID: 【ID】）。

カラーミーショップAPIを利用した WordPress プラグイン「Cart Bridge JP – Migrate for WooCommerce」を
開発しております。ショップオーナー様ご自身が、ご自身のカラーミーショップの商品・顧客・受注データを
WooCommerce へ移行（および WooCommerce からカラーミーショップへ登録）するためのツールで、
WordPress.org で無料配布する予定です。リモート側データの削除APIは使用せず、レート制限
（120リクエスト/分）を遵守する設計です。

現在は、ショップオーナー様ご自身にデベロッパー登録とプライベートアプリの作成をしていただき、
client_id / client_secret をプラグインに入力いただく方式で実装しています。しかし開発者でない
ショップオーナー様にはこの手順が難しいため、以下の提供形態を検討しています。

【検討中の提供形態】
- 弊社がプライベートアプリを1つ登録し、リダイレクトURIは弊社サーバーの固定URLとする
- ショップオーナー様は、カラーミーショップの認可画面でログインし「連携」を承認するだけ
- client_secret は弊社サーバーのみが保持し、認可コードとアクセストークンの交換は弊社サーバーで
  行ったうえで、発行されたアクセストークンをショップオーナー様ご自身の WordPress サイトへ
  安全に引き渡す（弊社サーバーにはトークンを保存しない）
- アプリストアへの掲載は現時点では予定しておりません

つきましては、以下の点をご教示ください。

1. アプリストアに公開していないプライベートアプリを、弊社以外の複数のショップオーナー様に
   認可・利用いただくことは、API利用規約（第4条「再許諾不可」、第8条「本件API又はその
   ライセンスを販売、賃貸、再許諾する行為」）に抵触しないでしょうか。
2. 技術的に、1つのプライベートアプリを複数のショップが認可し、ショップごとにアクセス
   トークンを発行することは可能でしょうか。認可可能なショップ数に上限はありますか。
3. 上記の提供形態の場合、アプリストアモードへの切替（アプリストア公開）は必須でしょうか。
   プライベートアプリのまま提供してもよいでしょうか。
4. client_secret を再発行した場合、発行済みのアクセストークンは失効しますか。
5. 認可画面に表示されるアプリ名・提供者情報はどのように決まりますか（ショップオーナー様が
   正規のアプリであることを確認できる表示についてお伺いしたいです）。

お忙しいところ恐れ入りますが、ご確認のほどよろしくお願いいたします。

【会社名】
【氏名】
【メールアドレス】
```

任意で追加する設問（案Cを視野に入れる場合のみ。回答次第でアプリストア経由の販売を求められる可能性があるため、
送付前に判断すること）:

```
6. 将来アプリストアに「無料プラン」で掲載し、拡張機能を WordPress プラグインとして弊社サイトで
   販売した場合、よくある質問の「無料アプリでの自社課金は禁止」に該当しますか。
```

### A.2 BASE API サポート宛

送付先: ヘルプ記事「BASE APIについて、専用のサポート窓口はありますか」
（ https://help.thebase.in/hc/ja/articles/9841826040217 ）に案内されている窓口。
件名案: 「BASE API の利用形態（データ移行ツール・複数ショップ認可）についての確認」

```
BASE API サポート ご担当者様

お世話になっております。【会社名】の【氏名】と申します（BASE Developers 登録メールアドレス: 【アドレス】、
アプリ名: 【アプリ名】）。

BASE API を利用した WordPress プラグイン「Cart Bridge JP – Migrate for WooCommerce」を開発しております。
ショップオーナー様ご自身が、ご自身の BASE ショップの商品・受注データを WooCommerce へ移行する
（および WooCommerce の商品・カテゴリ・在庫を BASE ショップへ登録する）ためのツールで、
WordPress.org で無料配布する予定です。削除系APIは使用せず、利用制限（5,000回/時・100,000回/日）を
遵守する設計です。

以下の点をご教示ください。

1. API利用規約 第8条1号「本API又は本情報を当社のサービスと競合するサービスのために使用する行為」に
   ついて、ショップオーナー様ご自身の判断でご自身のデータを WooCommerce へ移行するツールは、
   この条項に該当しますでしょうか。
2. 弊社が登録した1つのアプリケーション（非公開）を、弊社以外の複数のショップオーナー様が
   認可して利用することは可能でしょうか。規約上・技術上の制約があればお知らせください。
3. コールバックURLについて、https は必須でしょうか。複数登録や登録後の変更は可能でしょうか。
4. アクセストークンのリフレッシュ（refresh_token グラント）に client_secret が必要なため、
   client_secret を弊社サーバーのみで保持し、ショップオーナー様の WordPress サイトからの
   リフレッシュ要求を弊社サーバーが代行して BASE API へ送信する構成を検討しています。
   この構成に規約上の問題はありますでしょうか（弊社サーバーはトークンを保存しません）。

お忙しいところ恐れ入りますが、ご確認のほどよろしくお願いいたします。

【会社名】
【氏名】
【メールアドレス】
```

### A.3 送付・回答の記録

| 宛先 | 送付日 | 回答日 | 要旨 | 反映先 |
|---|---|---|---|---|
| カラーミー | 未送付 | — | — | §2.1.1 / §2.1.2 / §7 #2・#3・#7 |
| BASE | 未送付 | — | — | §2.2 / §7 #8・#9 |

---

## 改訂履歴

| 日付 | 内容 |
|---|---|
| 2026-09-06 | 初版。案A/B/C の比較、カラーミー・BASE・MakeShop・Pressable・サーバーレス基盤の調査結果、案Bのアーキテクチャ案、未確定事項を記録。付録Aに問い合わせ文面の下書きを追加 |
| 2026-09-06 | 位置づけを「v1.0 で導入検討」から **「v2.0 の検討事項」** に変更（v1.0 は現行の案Aで進める）。§1.3・§8 を v2.0 前提に書き換え、`docs/10-tasks.md` に B4-7 を追加 |
