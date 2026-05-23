# Azure Functions Code Interpreter API テスト用スクリプト

Azure FunctionsからAzure OpenAIのCode Interpreter機能を使用したデータ分析を行うAPIをテストするための方法を示します。

## 目次

1. [システム概要](#システム概要)
2. [前提条件](#前提条件)
3. [ローカル環境でのテスト](#ローカル環境でのテスト)
4. [Azure Functionsへのデプロイ](#azure-functionsへのデプロイ)
5. [Azure Functions上でのテスト](#azure-functions上でのテスト)
6. [トラブルシューティング](#トラブルシューティング)

---

## システム概要

このAPIは以下の機能を提供します:

- Excelファイル（.xlsx, .csv）のアップロード
- Azure OpenAI Code Interpreterによるデータ分析
- グラフの自動生成（日本語フォント対応）
- 分析結果とグラフの返却

### アーキテクチャ

```
クライアント → Azure Functions → Azure OpenAI (Code Interpreter)
```

---

## 前提条件

### 必要なツール

- Python 3.12.10
- Azure Functions Core Tools 4.3.0+
- Azure CLI
- VS Code（推奨）

### 必要なAzureリソース

- Azure OpenAI Service
  - GPT-4o デプロイメント
  - Code Interpreter機能が有効
- Azure Functions（Python 3.12）
- Azure Storage Account（Managed Identity使用）

---

## ローカル環境でのテスト

### 1. 環境構築

#### 1.1 仮想環境の作成

```powershell
cd .\app\
# 仮想環境を作成
python -m venv venv

# 仮想環境を有効化
.\venv\Scripts\Activate.ps1

# 依存パッケージをインストール
pip install -r requirements.txt
```

#### 1.2 環境変数の設定

`example_local.settings.json`を`local.settings.json`に名称変更して、以下の環境変数を設定します:

```json
{
  "IsEncrypted": false,
  "Values": {
    "AzureWebJobsStorage": "UseDevelopmentStorage=true",
    "FUNCTIONS_WORKER_RUNTIME": "python",
    "AZURE_OPENAI_ENDPOINT": "https://your-openai-resource.openai.azure.com",
    "AZURE_OPENAI_DEPLOYMENT_NAME": "gpt-4o",
    "AZURE_OPENAI_API_VERSION": "2024-05-01-preview",
    "FONT_FILE_ID": "assistant-xxxxxxxxxxxxx",
    "OPENAI_TIMEOUT": "300.0",
    "OPENAI_MAX_RETRIES": "3",
    "SYSTEM_PROMPT": "あなたはデータ分析のエキスパートです。..."
  }
}
```

**重要な設定項目:**

- `AZURE_OPENAI_ENDPOINT`: Azure OpenAIのエンドポイントURL
- `AZURE_OPENAI_DEPLOYMENT_NAME`: デプロイメント名（gpt-4o推奨）
- `FONT_FILE_ID`: 日本語フォントファイルのID（事前にアップロード必要）

#### 1.3 日本語フォントファイルのアップロード

```powershell
# 環境変数を設定
$env:AZURE_OPENAI_ENDPOINT="https://your-openai-resource.openai.azure.com"

# フォントファイルをアップロード
python upload_font.py

# 出力されたFile IDをlocal.settings.jsonのFONT_FILE_IDに設定
```

### 2. ローカルでFunctionsを起動

```powershell
# Azure Functionsをローカルで起動
func start
```

起動後、以下のURLでアクセス可能になります:
```
http://localhost:7071/api/code-interpreter/responses
```

### 3. ローカル環境でのテスト実行

**ローカル環境では認証が不要**なため、`--api-key`オプションは指定しません。

#### 3.1 単一テスト（1ワーカー、1テスト）

```powershell
python test_api_cli.py --workers 1 --num-tests 1
```

#### 3.2 並列テスト（3ワーカー、5テスト）

```powershell
python test_api_cli.py --workers 3 --num-tests 5
```

#### 3.3 全テストケース実行（20テスト）

```powershell
python test_api_cli.py --workers 5 --num-tests 20
```

#### 3.4 カスタムExcelファイルでテスト

```powershell
python test_api_cli.py --workers 1 --num-tests 1 --excel your_data.xlsx
```

**注意**: ローカル環境のデフォルトURLは`http://localhost:7071/api/code-interpreter/responses`です。

### テスト結果の確認

テスト結果は`output`ディレクトリに保存されます:

```
output/
├── test_summary_20251110131719.json  # テスト結果サマリー
├── test_1/
│   └── 20251110131719/
│       ├── output.txt                # テキスト出力
│       └── *.png                     # 生成されたグラフ
├── test_2/
│   └── ...
```

---

## Azure Functionsへのデプロイ

### 1. Azure リソースの作成

```powershell
# リソースグループを作成
az group create --name <resource-group-name> --location japaneast

# Storage Accountを作成
az storage account create `
  --name <storage-account-name> `
  --resource-group <resource-group-name> `
  --location japaneast `
  --sku Standard_LRS

# Function Appを作成（Python 3.12、Managed Identity有効）
az functionapp create `
  --name <function-resource-name> `
  --resource-group <resource-group-name> `
  --storage-account <storage-account-name> `
  --runtime python `
  --runtime-version 3.12 `
  --functions-version 4 `
  --os-type Linux `
  --assign-identity `
  -consumption-plan-location LOCATION
```

### 2. Managed Identityへの権限付与

#### 2.1 Storage Accountへの権限

```powershell
# Function AppのManaged Identity IDを取得
$identityId = az functionapp identity show `
  --name <function-resource-name> `
  --resource-group <resource-group-name> `
  --query principalId -o tsv

# Storage Blob Data Contributorロールを付与
az role assignment create `
  --assignee $identityId `
  --role "Storage Blob Data Contributor" `
  --scope "/subscriptions/<subscription-id>/resourceGroups/<resource-group-name>/providers/Microsoft.Storage/storageAccounts/<storage-account-name>"

# Storage Queue Data Contributorロールを付与
az role assignment create `
  --assignee $identityId `
  --role "Storage Queue Data Contributor" `
  --scope "/subscriptions/<subscription-id>/resourceGroups/<resource-group-name>/providers/Microsoft.Storage/storageAccounts/<storage-account-name>"

# Storage Table Data Contributorロールを付与
az role assignment create `
  --assignee $identityId `
  --role "Storage Table Data Contributor" `
  --scope "/subscriptions/<subscription-id>/resourceGroups/<resource-group-name>/providers/Microsoft.Storage/storageAccounts/<storage-account-name>"
```

#### 2.2 Azure OpenAIへの権限

```powershell
# Cognitive Services OpenAI Userロールを付与
az role assignment create `
  --assignee $identityId `
  --role "Cognitive Services OpenAI User" `
  --scope "/subscriptions/<subscription-id>/resourceGroups/<openai-resource-group>/providers/Microsoft.CognitiveServices/accounts/<openai-resource-name>"
```

### 3. アプリケーション設定

```powershell
az functionapp config appsettings set `
  --name <function-resource-name> `
  --resource-group <resource-group-name> `
  --settings `
    "AZURE_OPENAI_ENDPOINT=https://your-openai-resource.openai.azure.com" `
    "AZURE_OPENAI_DEPLOYMENT_NAME=gpt-4o" `
    "FONT_FILE_ID=assistant-xxxxxxxxxxxxx" `
    "OPENAI_TIMEOUT=300.0" `
    "OPENAI_MAX_RETRIES=3"
```

**SYSTEM_PROMPTの設定:**

改行を含むため、Azure Portalから設定することを推奨:

1. Azure Portal → Function App → 構成 → アプリケーション設定
2. 「新しいアプリケーション設定」をクリック
3. 名前: `SYSTEM_PROMPT`
4. 値: `local.settings.json`のSYSTEM_PROMPTの内容をコピー
5. 保存

### 4. デプロイ

```powershell
# VS Code Azure Functions拡張機能を使用してデプロイ
# または、Azure CLIでデプロイ

# コードをzipに圧縮（.funcignoreに従って除外）
Compress-Archive -Path * -DestinationPath deploy.zip -Force

# Function Appにデプロイ
az functionapp deployment source config-zip `
  --name <function-resource-name> `
  --resource-group <resource-group-name> `
  --src deploy.zip
```

### 5. デプロイ後の確認

```powershell
# Function Appの状態を確認
az functionapp show `
  --name <function-resource-name> `
  --resource-group <resource-group-name> `
  --query state

# Function Appを再起動（設定反映のため）
az functionapp restart `
  --name <function-resource-name> `
  --resource-group <resource-group-name>
```

---

## Azure Functions上でのテスト

### 1. Function URLとキーの取得

#### Azure Portalから取得:

1. Azure Portal → Function App → 関数 → `code_interpreter_responses_endpoint`
2. 「関数のキーを取得」をクリック
3. デフォルトキーの値をコピー

#### Azure CLIから取得:

```powershell
# Function App全体のマスターキーを取得
az functionapp keys list `
  --name <function-resource-name> `
  --resource-group <resource-group-name>
```

### 2. テストの実行

#### 2.1 ヘッダー形式（推奨）

Function Keyを`x-functions-key`ヘッダーとして使用:

```powershell
python test_api_cli.py `
  --api-url "https://<function-resource-name>.azurewebsites.net/api/code-interpreter/responses" `
  --api-key "YOUR_FUNCTION_KEY" `
  --workers 1 `
  --num-tests 1 `
  --excel sample_data.csv
```

**注意**: 
- ローカル環境では`--api-key`は不要です（認証なし）
- Azure Functions環境では`--api-key`でFunction Keyを指定してください

### 3. 並列テストの実行

#### 軽量テスト（10並列、10テスト）

```powershell
python test_api_cli.py `
  --api-url "https://<function-resource-name>.azurewebsites.net/api/code-interpreter/responses" `
  --api-key "YOUR_FUNCTION_KEY" `
  --workers 10 `
  --num-tests 10 `
  --excel sample_data.xlsx
```

#### 全テストケース（10並列、20テスト）

```powershell
python test_api_cli.py `
  --api-url "https://<function-resource-name>.azurewebsites.net/api/code-interpreter/responses" `
  --api-key "YOUR_FUNCTION_KEY" `
  --workers 10 `
  --num-tests 20 `
  --excel sample_data.xlsx
```

### 4. テスト結果の確認

```powershell
# 最新のテスト結果サマリーを確認
Get-Content .\output\test_summary_*.json | Select-Object -Last 1 | ConvertFrom-Json | ConvertTo-Json -Depth 10
```

**成功率の目安:**

- **90%以上**: 正常
- **80-90%**: タイムアウト発生（Premium Planへのアップグレード推奨）
- **80%未満**: 設定または権限の問題を確認

### 認証方法のまとめ

| 環境 | 認証方法 | コマンド例 |
|-----|---------|-----------|
| ローカル | 認証なし | `python test_api_cli.py --workers 3` |
| Azure Functions | x-functions-keyヘッダー | `python test_api_cli.py --api-url https://your-function.azurewebsites.net/api/code-interpreter/responses --api-key YOUR_KEY` |

---

## トラブルシューティング

### 1. ローカル環境のエラー

#### エラー: "AZURE_OPENAI_ENDPOINT is not set"

**原因**: 環境変数が設定されていない

**解決策**:
```powershell
# local.settings.jsonを確認
Get-Content .\local.settings.json

# 環境変数が正しく設定されているか確認
$env:AZURE_OPENAI_ENDPOINT
```

#### エラー: "DefaultAzureCredential failed to retrieve a token"

**原因**: Azure CLIでログインしていない

**解決策**:
```powershell
# Azure CLIでログイン
az login

# 正しいサブスクリプションを設定
az account set --subscription "YOUR_SUBSCRIPTION_ID"
```

#### エラー: "Host restarted" ループ

**原因**: ファイル監視が原因で再起動を繰り返す

**解決策**:
`host.json`に以下を追加（既に設定済み）:
```json
{
  "watchDirectories": [],
  "watchFiles": []
}
```

### 2. Azure Functions デプロイのエラー

#### エラー: "Storage access 403 Forbidden"

**原因**: Managed IdentityにStorage権限がない

**解決策**:
```powershell
# Managed Identity IDを確認
az functionapp identity show --name <function-resource-name> --resource-group <resource-group-name>

# Storage権限を再付与（上記「Managed Identityへの権限付与」参照）
```

#### エラー: "Token tenant does not match resource tenant"

**原因**: Azure CLIのテナントとリソースのテナントが異なる

**解決策**:
```powershell
# 正しいテナントでログイン
az login --tenant YOUR_TENANT_ID

# テナントを確認
az account show --query tenantId
```

### 3. Azure Functions 実行時のエラー

#### エラー: "Connection error"

**原因**: Azure OpenAIへの接続権限がない

**解決策**:
```powershell
# Managed IdentityにCognitive Services OpenAI Userロールを付与
# （上記「Azure OpenAIへの権限」参照）

# 権限付与後、Function Appを再起動
az functionapp restart --name <function-resource-name> --resource-group <resource-group-name>
```

#### エラー: "504 Gateway Timeout"

**原因**: リクエストがタイムアウト制限（230秒）を超えた

**解決策**:

1. **Premium Planへのアップグレード（推奨）**:
   ```powershell
   az functionapp plan create `
     --name premium-plan `
     --resource-group <resource-group-name> `
     --location japaneast `
     --sku EP1 `
     --is-linux
   
   az functionapp update `
     --name <function-resource-name> `
     --resource-group <resource-group-name> `
     --plan premium-plan
   ```

2. **処理の最適化**: 複雑な分析を分割して実行

3. **リトライロジック**: クライアント側でリトライを実装

### 4. テストスクリプトのエラー

#### エラー: "JSON decode error"

**原因**: APIからのレスポンスが不正

**解決策**:
```powershell
# デバッグ情報を有効にして実行
# test_api_parallel.pyで詳細エラーを確認
```

#### エラー: "FileNotFoundError: sample_data.xlsx"

**原因**: テストファイルが存在しない

**解決策**:
```powershell
# ファイルの存在を確認
Test-Path .\sample_data.xlsx

# または、CSVファイルを使用
python test_api_cli.py --excel sample_data.csv --workers 1 --num-tests 1
```

---

## 参考情報

### ファイル構成

```
.
├── function_app.py              # メインのFunctionコード
├── requirements.txt             # Python依存パッケージ
├── host.json                    # Functions設定
├── local.settings.json          # ローカル環境変数
├── .funcignore                  # デプロイ除外ファイル
├── test_api_cli.py              # テストスクリプト（CLI）
├── test_api_parallel.py         # 並列テストロジック
├── upload_font.py               # フォントアップロードスクリプト
├── sample_data.csv              # サンプルデータ（CSV）
├── sample_data.xlsx             # サンプルデータ（Excel）
└── font/
    └── ipaexg.ttf.zip          # 日本語フォント
```

### 関連ドキュメント

- [Azure Functions Python開発者ガイド](https://learn.microsoft.com/azure/azure-functions/functions-reference-python)
- [Azure OpenAI Code Interpreter](https://learn.microsoft.com/azure/ai-services/openai/how-to/code-interpreter)
- [Azure Managed Identity](https://learn.microsoft.com/azure/active-directory/managed-identities-azure-resources/)

