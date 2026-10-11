# 日英共通お問い合わせフォーム

## 適用内容
- 共通フォーム `/contact/`。従来のデザインを維持し、項目・確認・完了表示を日英併記。
- トップにメールアイコン＋CONTACT / お問い合わせ。国内・海外の既存リンクも共通フォームへ変更。
- `エントリー受付の確認` と任意の受付番号。既存の海外フォームURLは共通フォームへ誘導し、受付番号の引継ぎにも対応。
- 返信先はE-mail、WhatsApp、FB Messenger、予備連絡先は任意。メールアドレスを必須にはしない。
- 保存後、事務局 `osaka@sakuradragoncup.jp` へ通知。参加者への自動返信は実装しない。

## さくらの既存設定を使用
`/home/shibainupochi/private-sdc-entry/config.php` の既存 `storage_dir`、`notification_sender` を使用。
送信者 `sdc_osaka@transcendent.fun`、宛先 `osaka@sakuradragoncup.jp`。
`enabled => false` はエントリー専用で変更不要。問い合わせは11月1日を待たず受け付ける。
問い合わせを停止する場合だけ、私有設定に `contact_enabled => false` を追加する。
DBは同じ私有ディレクトリの `contacts.sqlite` に自動作成。既存 `entries.sqlite` と受付日・料金判定は変更しない。
通知が送信処理で失敗しても保存内容は残る。未送信の再通知用CLIを `tools/retry-contact-notifications.php` に同梱した。toolsは既存デプロイで除外されるので、必要時はSSH端末内でCLIファイルを配置して実行する。mail()のtrueは配送受付を表すため、実受信は別途確認する。

## デスクトップ版Codexへの指示
1. 最新mainで添付差分を適用。競合があれば、この修正範囲で解決。
2. PHP構文、ローカル保存・再送重複防止・CSRF・入力チェック・通知失敗時の保存維持を確認。ブラウザでPC/スマートフォン幅と入力→確認→送信→完了を確認。
3. コミットしてPush。さくらの自動デプロイ成功を確認。
4. `/contact/` でテスト送信し、完了番号と事務局通知メールの実受信を確認。エントリー受付は停止したままにする。
5. 本公開日程や海外エントリー開始日はトップに追記しない。Coming soonの2つのボタンは変更しない。

## 検証状況
- PHP 8.5実行環境で入力・私有SQLite保存・重複防止・連絡先検証・通知失敗時の保存維持・再通知の単体テストに合格。
- ローカルHTTP＋DOM実行で、入力→確認→送信→受付番号表示、再送時の同一番号、CSRF拒否、同一IP送信制限を確認。エントリーの enabled=false でも問い合わせ送信できることを確認。
- JavaScript構文とgit diffの空白チェックに合格。
- Edge実ブラウザでトップ・共通問い合わせ・国内・海外ページを320〜1440pxの7幅で確認。横方向のはみ出し、画像読み込みエラー、JavaScriptエラーなし。PC・スマートフォンのスクリーンショットも確認。
- E-mail・WhatsApp・FB Messenger、予備連絡先の表示切替、受付番号不明、旧URLからの番号引継ぎ、入力エラー、確認・修正・完了画面を確認。保存後に通信が切れた場合の再送でも重複しない。
同梱テスト `tests/contact-unit.php` は私有の一時DBと偽のメール配送関数で検証し、実メールは送らない。
`tests/contact-browser.cjs` も公開領域外の一時データと通知失敗用の配送関数で検証する。PHP実行ファイルは環境変数 `PHP_BIN`、Playwrightは `PLAYWRIGHT_MODULE` で指定できる。
本番のブラウザ送信と実メール到着は、適用・デプロイ後に行う。
