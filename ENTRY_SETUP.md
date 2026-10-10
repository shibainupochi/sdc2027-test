# 海外チームエントリー：設定・検証・公開手順

2026年10月11日。ローカル実装用。今回、本番受付開始・commit・push・デプロイは行っていません。

## 実装の配置

- `/en/entry/`：薄いピンク・白・紺色の入力、確認、受付控え画面。
- `en/entry/submit.php` / `lib.php`：PHP 8.1以上・PDO SQLite。サーバー時刻をAsia/Tokyoへ変換し、2026年11月1日00:00以上、2027年3月1日00:00未満を受付期間とする。2月1日00:00未満80,000円、それ以降100,000円。
- サーバー保存が成功してから控えを表示。通知は保存後。失敗時はDBの`notified=0`として残り、受付自体は成功する。
- 送信キーはサーバーが発行しセッションに紐づける。同じキー・同じ内容の再送は同じ受付番号・日時・料金を返す。キーに対する内容変更は409で拒否。他のセッションから控えは読めない。
- 通信失敗後は同じ内容で再送する。受付済みの再送・控え復元は期限後も可能。ブラウザーのセッションが失効した場合は控えを自動復元できないため、受付番号とスクリーンショットを保管する。
- 申込内容はブラウザーのlocalStorage/sessionStorageに保存しない。sessionStorageには再送用のランダムキーだけを置く。
- 国内料金・国内募集時期は変更しない。海外の`ENTRY_URL`は空値のまま。受付APIは設定がない場合503、`enabled=false`または期間外は新規受付不可。
- 問い合わせは`Entry Status Inquiry`と任意の受付番号を追加。**問い合わせ送信処理は未接続**で、メールへの案内と送信不可表示を維持。

## さくら側の具体的な設定

以下の作業は次回、公開・サーバー変更の承認後に行う。サーバーアカウントは既存workflowの`shibainupochi`を基準にした例。契約環境が異なる場合は読み替える。

### 1. PHP / SQLite / HTTPSを確認

サーバーコントロールパネルの「スクリプト設定」→「言語のバージョン設定」で、サポートされているPHP 8.2以上を選ぶ（コードの最低要件は8.1）。他のアプリへの影響を先に確認する。

SSHで確認：

```sh
php -v
php -r 'echo PHP_VERSION, "\n"; var_export(PDO::getAvailableDrivers()); echo "\n";'
```

`sqlite`ドライバーが必要。SSHのCLIとWeb用PHPはバージョン・拡張が一致するとは限らないため、WebのPOST保存もステージングで確認する。PHPがソースとして配信されるサーバーには配置しない。正式公開ではHTTPS必須。Web用PHPのエラー表示はOFF、エラー記録はONにする。既存のphp.iniは丸ごと上書きしない。

参照：[さくらのPHP/CGI設定](https://help.sakura.ad.jp/rs/2252/)、[php.ini設定](https://help.sakura.ad.jp/rs/2889/)。

### 2. 非公開保存先と設定を作成

`www`配下へ置かない。Gitチェックアウトの中にも置かない。

```sh
umask 077
mkdir -p /home/shibainupochi/private-sdc-entry
chmod 700 /home/shibainupochi/private-sdc-entry
```

`tools/entry-config.example.php`を基準に、次の設定を**非公開の**`/home/shibainupochi/private-sdc-entry/config.php`へ作成する。

```php
<?php
return [
    'enabled' => false,
    'storage_dir' => '/home/shibainupochi/private-sdc-entry',
    'notification_sender' => '', // さくらから送信を許可された実在アドレスを設定
];
```

```sh
chmod 600 /home/shibainupochi/private-sdc-entry/config.php
```

APIは`SDC_ENTRY_CONFIG`環境変数の絶対パスを優先する。未指定時は公開ルートの`www`の親にある`private-sdc-entry/config.php`を読む。上記の標準配置なら環境変数は不要。独自のDOCUMENT_ROOT構成ならパスの決定を確認し、必要に応じてWeb用PHPの環境変数を指定する。`.htaccess`のSetEnvだけでPHPへ渡ると決めつけない。

公開ルート、`www`内の別ディレクトリ、リポジトリ内、そこへ向いたシンボリックリンクは保存先・設定先として拒否する。PHP実行ユーザーが保存先へ書き込めることを確認。DBは自動作成され、権限600となる。ZIP実装案の古いDBは新しいスキーマと異なるため、そのまま再利用しない。既存実データがある場合はバックアップし、移行を別途検証する。

### 3. 通知送信元を設定して到着を検証

通知先は`osaka@sakuradragoncup.jp`で固定。`notification_sender`には、さくらからの送信を許可され、認証設定を確認した送信元を設定する。Workspaceのアドレスを根拠なくFromへ指定しない。この実装はPHP `mail()`を使用し、SMTP/OAuthは実装していない。

さくらのsendmail環境、差出人ドメインのSPF/DKIM等、受信側の迷惑メール判定を確認する。DNS設定変更は今回未実施。**`mail()`のtrueは送信サーバーへの引渡しで、受信箱への到着を保証しない。**テスト申込1件で実際の受信箱と迷惑メールフォルダーを確認し、受付番号・申込内容・日本時間・料金を照合する。

### 4. 非公開ステージングで実機確認

アクセス制限した別配置・別DBで、Web PHP、SQLite保存、セッションCookie、連絡方法、控え、CSV、メール到着を確認する。現時点は受付期間前のため、本番で`enabled=true`にしても受付は開始しない。期間境界はローカルテストの一時コピーだけで時計を差し替えて検証した。**本番コードには試験時刻の設定機能を設けていない。**さくらで期間外の受付テストをするなら、非公開ステージングのコピーだけで期間を変更し、試験後に破棄する。本番の期間を変えない。

保存失敗、通知失敗、送信後の通信切断、同一送信の連打、期限後再送を確認する。保存先・config・DBをHTTPで取得できないことと、`.htaccess`で`lib.php`が403になることも実サーバーで確認する。

### 5. 一覧・CSV・通知再送・バックアップ

`tools/`と`tests/`は自動デプロイ対象から除外。管理用PHPスクリプトは公開領域外の運用用チェックアウトに置く（コードのみ。DB/configはそのGitチェックアウト外）。管理用チェックアウトには`en/entry/lib.php`も必要。SSH/CLIでのみ実行可能で、HTTP実行は404。

```sh
cd /home/shibainupochi/sdc2027-ops
export SDC_ENTRY_CONFIG=/home/shibainupochi/private-sdc-entry/config.php
php tools/export-entries.php --list
umask 077
php tools/export-entries.php > /home/shibainupochi/private-sdc-entry/entries.csv
php tools/retry-entry-notifications.php
# 未通知一覧を確認してから、明示的に送信を再試行する：
php tools/retry-entry-notifications.php --send
```

CSVはUTF-8で、料金・通知状態・通知試行回数・申込全項目を含む。Excel等では文字コードUTF-8として取り込む。数式注入対策として危険な先頭文字へアポストロフィを付加する。CSVを公開ディレクトリへ出力しない。

未通知を定期的に確認する。再送スクリプトを同時に複数起動しない。引渡し済みでも到着不明の場合は受信状況を確認してから個別対応する。DB更新とメール配送は単一トランザクションにできないため、配送途中のプロセス停止では通知だけ重複する可能性がある（申込登録は重複しない）。

DBのバックアップはSQLiteのオンラインバックアップ機能または書込み停止中のコピーを使用し、非公開領域へ保管。復元試験、担当者、個人情報の保管期間と削除手順を公開前に決定する。

### 6. 受付公開は承認後

まず問い合わせフォームの実送信処理を接続・到着検証する。今回の追加だけでは問い合わせは送信されない。必要ならLINEで受け付けた申込に返信できる事務局の運用も確認する。

本番設定の`enabled`をtrueにするのは承認後。受付期間は固定で、11月1日00:00 JSTからのみ新規保存可能。OPcache使用環境では設定変更の再読み込みを確認する。ENTRYボタンを表示する際は`en/js/config.js`の`ENTRY_URL`を`'./entry/'`へ変更する。本作業では空値を維持。

GitHub Desktopで差分確認→commit。**mainへのpushは既存workflowにより自動デプロイを開始する**ので、公開承認までpushしない。workflowは公開HTML/PHPのみを対象とし、管理ツール・テスト・DB・CSV・実設定を除外する。DNS/SSL/ホスティングの変更はしない。

## ローカル検証方法

```sh
php -l en/entry/lib.php
php -l en/entry/submit.php
php -l tools/export-entries.php
php -l tools/retry-entry-notifications.php
php tests/entry-unit.php
```

PDO SQLiteが必要。ブラウザーテストは`node tests/entry-browser.cjs`（PHP_BINにPHP絶対パス、PLAYWRIGHT_MODULEにPlaywrightパッケージパスを設定可）。同テストは合成データのみを使用し、OSの一時フォルダーにWeb配置と**その外側**のDB/configを作成する。試験時刻は一時コピーだけへ適用。実メールは送らない。

検証結果と本番確認の区別：PHP 8.5.11で構文・SQLite・JST境界・重複抑止・通知失敗時保持・CSVを実行検証。PC/スマホの表示とPHP API連携をローカルで検証。さくら実環境のPHP/権限/HTTPS/Apache制御、実メール到着、問い合わせ実送信、本番デプロイは未検証・未実施。
