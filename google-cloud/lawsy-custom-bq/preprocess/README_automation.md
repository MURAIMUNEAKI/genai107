# e-Laws Data Update Workflows

このドキュメントは、e-Gov法令APIから法令データを自動取得してBigQueryに反映するGitHub Actionsワークフローについて説明します。

## 概要

法令データを定期的に更新し、BigQueryの`e_laws_search`データセットに反映する自動化ワークフローです。

### 処理フロー

1. e-Gov法令APIから最新の法令XMLデータをダウンロード
2. ダウンロードしたZIPファイルを解凍
3. 日付付きのGCSバケットを作成（30日で自動削除のライフサイクルポリシー付き）
4. `run_entire_pipeline.sh`を実行してBigQueryにデータをロード
   - Source層: `source_laws_latest` テーブル（固定名で上書き）
   - DWH層とApp層も自動更新
5. 一時ファイルをクリーンアップ

## ワークフロー一覧

GitHub Actionsワークフローは `.github/workflows/` に配置されています。

### 1. Sandbox環境 (`elaws-data-update-sandbox.yaml`)

- **トリガー**: `env/elaws-to-bq-sandbox` ブランチへのpush
- **プロジェクト**: `YOUR_SANDBOX_PROJECT_ID`
- **GCSバケット**: `YOUR_SANDBOX_PROJECT_ID-elaws-YYYYMMDD`

### 2. Staging環境 (`elaws-data-update-stg.yaml`)

- **トリガー**: `env/elaws-to-bq-stg` ブランチへのpush
- **プロジェクト**: `YOUR_STAGING_PROJECT_ID`
- **GCSバケット**: `YOUR_STAGING_PROJECT_ID-elaws-YYYYMMDD`

### 3. Production環境 (`elaws-data-update-prd.yaml`)

- **トリガー**:
  - `env/elaws-to-bq-prd` ブランチへのpush
  - 定期実行: 毎週月曜日 3:00 JST (cron: `0 18 * * 0`)
- **プロジェクト**: `YOUR_PROD_PROJECT_ID`
- **GCSバケット**: `YOUR_PROD_PROJECT_ID-elaws-YYYYMMDD`
- **特記事項**: GitHub Environment による承認が必要

## 設定値

| パラメータ | 値 |
|-----------|-----|
| dataset_id | `e_laws_search` |
| source_table | `source_laws_latest` (固定名) |
| gcs_blob_name | `data.jsonl` |
| region | `asia-northeast1` |
| connection_name | `lawsy-bq-connection` |

## データ管理

### BigQueryテーブル

- **Source層**: `source_laws_latest`
  - 固定テーブル名を使用し、実行ごとに上書き
  - 過去のデータは保持しない（最新版のみ）

### GCSバケット

- **命名規則**: `{PROJECT_ID}-elaws-{YYYYMMDD}`
- **ライフサイクルポリシー**: 作成から30日後に自動削除
- **目的**: BigQueryロード用の一時データ保存

## 使い方

### 手動実行（任意の環境）

1. 該当するブランチを作成またはチェックアウト
   ```bash
   # Sandbox環境の例
   git checkout -b env/elaws-to-bq-sandbox
   ```

2. 変更をコミット＆プッシュ
   ```bash
   git commit --allow-empty -m "Trigger e-Laws data update"
   git push origin env/elaws-to-bq-sandbox
   ```

3. GitHub Actionsで実行状況を確認
   - Production環境の場合は承認が必要

### 定期実行（Production環境のみ）

毎週月曜日の深夜3時（JST）に自動実行されます。手動での操作は不要です。

## トラブルシューティング

### GCSバケット名の衝突エラー

```
ERROR: The requested bucket name is not available.
```

**原因**: GCSバケット名はグローバルでユニークである必要があるため、同じ日に複数環境で実行すると衝突します。

**解決**: プロジェクトIDがバケット名に含まれているため、環境ごとに異なるバケット名が生成されます。

### Production環境でタイムアウト

```
google.auth.exceptions.RefreshError: upstream request timeout
```

**原因**: GitHub Environment `google-cloud-ai-env-production` で承認待ちのままタイムアウトしています。

**解決**: GitHub ActionsのワークフローページでReview deploymentsから承認してください。

### Pythonライブラリのインポートエラー

**原因**: 必要なライブラリがインストールされていません。

**解決**: ワークフローに以下のライブラリが含まれていることを確認してください:
- `google-cloud-bigquery`
- `google-cloud-storage`
- `tqdm`

## 認証情報

各環境で以下のGitHub Secretsを使用しています:

### Sandbox
- `GCP_PROJECT_NUMBER_SANDBOX`
- `GCP_SERVICE_ACCOUNT_EMAIL_SANDBOX`

### Staging
- `GCP_PROJECT_NUMBER_STG`
- `GCP_SERVICE_ACCOUNT_EMAIL_STG`

### Production
- `GCP_PROJECT_NUMBER_PRD`
- `GCP_SERVICE_ACCOUNT_EMAIL_PRD`

## 関連ドキュメント

- [lawsy-custom-bq/README.md](../README.md) - Lawsy Custom BQプロジェクトの全体説明
- [run_entire_pipeline.sh](./run_entire_pipeline.sh) - このディレクトリのデータパイプラインスクリプト
- [e-Gov法令API](https://laws.e-gov.go.jp/bulkdownload?file_section=1&only_xml_flag=true) - 法令データのダウンロード元
- [GitHub Actions workflows](../../../../.github/workflows/) - ワークフローファイルの実体

## メンテナンス

### 定期実行スケジュールの変更

Production環境の定期実行スケジュールを変更する場合は、`.github/workflows/elaws-data-update-prd.yaml`の`schedule.cron`を編集してください。

```yaml
schedule:
  # 例: 毎日午前2時 JST (17:00 UTC前日)
  - cron: '0 17 * * *'
```

cron式はUTCタイムゾーンで記述します（JSTから9時間引く）。

### GCSバケットのライフサイクルポリシー変更

デフォルトは30日ですが、変更する場合は各ワークフローの`Create GCS bucket with lifecycle policy`ステップを編集してください。

```yaml
"age": 30  # この数値を変更
```
