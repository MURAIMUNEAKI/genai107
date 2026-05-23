# BigQueryとVertex AIの接続設定ガイド

BigQueryからVertex AIのAIモデルを呼び出すためには、事前に「接続（Connection）」を作成し、適切なIAM権限を付与する必要があります。

以下に、Google Cloudコンソール（GUI）を使用する方法と、`gcloud`コマンドラインツール（CLI）を使用する方法の2通りを記載します。

---

## Google Cloudコンソール（GUI）を使用する方法

### BigQueryの外部接続の設定
1.  **BigQueryの画面を開く**
    Google Cloudコンソールで、左側のナビゲーションメニューから「BigQuery」を選択します。

2.  **接続の作成を開始**
    BigQueryの画面で、「エクスプローラ」パネルの右上にある「**＋追加**」ボタンをクリックし、「**外部データソースへの接続**」を選択します。

3.  **接続タイプを選択**
    「接続タイプ」のリストから「**Vertex AIリモートモデル、リモート関数、BigLakeテーブル**」を選択します。

4.  **接続IDの入力**
    「接続ID」に、この接続を識別するための名前を入力します（例: `lawsy-bq-connection`）。この名前は後ほどSQLクエリで使います。

5.  **サービスアカウントIDのコピー**
    接続IDを入力すると、その下に「**サービスアカウントID**」が自動的に生成されます。このID（メールアドレス形式）をコピーしてください。

6.  **IAMページで権限を付与**
    新しいタブでGoogle Cloudコンソールの「**IAMと管理**」ページを開きます。
    - 「**アクセス権を付与**」をクリックします。
    - 「**新しいプリンシパル**」の欄に、先ほどコピーしたサービスアカウントIDを貼り付けます。
    - 「**ロールを選択**」で、「**Vertex AI ユーザー**」というロールを検索して選択します。
    - 「**保存**」をクリックします。

7.  **接続の作成を完了**
    BigQueryの接続作成画面に戻り、「**接続を作成**」ボタンをクリックします。

---

## gcloud CLI を使用する方法

CLIを使用すると、これらの設定をスクリプト化し、自動化することが可能です。

### 前提
- `gcloud` コマンドラインツールがインストールされ、認証済みであること。
- `bq` コマンドラインツールが利用可能であること。

### 手順

1.  **接続の作成**
    以下のコマンドを実行して、BigQueryの接続を作成します。`<project-id>`、`<region>`、`<connection-id>`はご自身の環境に合わせて書き換えてください。

    ```bash
    bq mk --connection --location=<region> --project_id=<project-id> \
        --connection_type=CLOUD_RESOURCE <connection-id>
    ```

2.  **サービスアカウントIDの取得**
    作成した接続の詳細情報を表示し、サービスアカウントIDを取得します。以下のコマンドを実行し、出力された`serviceAccountId`の値をコピーしてください。

    ```bash
    bq show --connection --project_id=<project-id> --location=<region> <connection-id>
    ```

3.  **IAM権限の付与**
    取得したサービスアカウントIDに対して、「Vertex AI ユーザー」のロールを付与します。`<project-id>`と`<service-account-id>`を書き換えて、以下のコマンドを実行してください。

    ```bash
    gcloud projects add-iam-policy-binding <project-id> \
        --member=serviceAccount:<service-account-id> \
        --role=roles/aiplatform.user
    ```

これで、CLIによる接続設定は完了です。