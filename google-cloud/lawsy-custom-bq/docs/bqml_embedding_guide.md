# BQML法令検索パイプライン構築ガイド（条単位チャンキング版）

このガイドでは、BigQuery ML (BQML) を使用した法令検索データパイプラインの構築・運用手順を説明します。

## 1. 概要

本パイプラインは、ローカルの法令XMLファイルを**条（Article）単位**でチャンキングし、BigQuery上に検索用のテーブルとインデックスを構築します。データの流れは、Source層、DWH層、App層の3層アーキテクチャで管理されます。

パイプラインの実行は、`run_entire_pipeline.sh`スクリプトによって完全に自動化されており、GCSバケットやBigQueryデータセットの初回作成も含まれます。

## 2. 事前準備

ユーザーが行う必要がある作業は、以下の初回セットアップのみです。

1.  **BigQuery Connectionの作成（初回のみ）:**
    - `docs/bq_connection_guide.md` の手順に従い、BigQueryとVertex AIの接続を作成します。この接続名を、パイプライン実行時に引数として指定します。

2.  **ローカル環境のセットアップ:**
    - `gcloud auth application-default login` を実行します。
    - 必要なPythonライブラリ（`google-cloud-bigquery`, `google-cloud-storage`など）をインストールします。

---

### **重要: BQMLモデルの選定と管理**

このパイプラインは、法令名のベクトル化にGoogle Cloudの基盤モデルを利用します。この設定は検索性能に直結するため、非常に重要です。

- **現在の設定モデル:** `gemini-embedding-001`
- **モデルの更新:** パイプライン実行前に、[Google Cloud ドキュメント](https://cloud.google.com/vertex-ai/generative-ai/docs/embeddings/get-text-embeddings)で最新の推奨 embedding モデルを確認し、必要であれば `run_bq_pipeline.py` 内のモデル作成クエリを更新してください。

---

## 3. パイプライン実行手順

事前準備が完了したら、`run_entire_pipeline.sh` を実行するだけで、すべての処理が自動的に行われます。

**コマンド:**
```bash
chmod +x google-cloud/lawsy-custom-bq/preprocess/run_entire_pipeline.sh

./google-cloud/lawsy-custom-bq/preprocess/run_entire_pipeline.sh <project_id> <dataset_id> <source_directory> <gcs_bucket_name> <gcs_blob_name> <region> <connection_name>
```

**引数:**
- `dataset_id`: BigQueryのデータセットID。存在しない場合は自動作成されます。
- `gcs_bucket_name`: GCSバケット名。存在しない場合は自動作成されます。
- `connection_name`: 事前準備で作成したBigQuery接続名。

## 4. 最終確認：エンドツーエンド検索テスト

パイプラインが正常に完了したら、最後にエンドツーエンドの検索テストを行い、システム全体が正しく機能していることを確認します。

詳細は、以下のガイドを参照してください。

- **[エンドツーエンド検索テストガイド](./verification_guide.md)**

## 5. (参考) Appendix: データモデルとSQL

### パーサーの仕様詳細 (`load_to_bq.py`)

- **チャンキング単位:** 処理の基本単位は`<Article>`（条）です。
- **処理範囲:** 
    - `<MainProvision>`（本則）配下の全条文を処理します。
    - `AmendLawNum`属性を持たない`<SupplProvision>`（原始附則）配下の全条文を処理します。
    - `AmendLawNum`属性を持つ「改正附則」は、元の法律のデータではないため、完全に無視します。
- **除外項目:** `<Article Delete="true">` のように、廃止済みの属性を持つ条文は処理から除外します。
- **主要な列の生成ロジック:**
    - `law_title`: `<LawTitle>`タグ配下の全テキストを再帰的に取得し、完全な法令名を格納します。
    - `content`: `<Article>`タグ配下の全テキスト（項、号、細分、表などすべて）を、構造を模した改行やインデントを加えて整形した上で、一つの文字列として格納します。
    - `unique_anchor`: `Main_Article_1`や`Suppl_Article_1`のように、本則・附則を区別するプレフィックスを付け、DWHのマージキーとしての一意性を保証します。
    - `anchor`: `Mp-At_1`というe-Gov仕様の形式で、本則の条にのみ生成します。外部リンク生成にのみ使用し、それ以外（附則など）はNULLとなります。

### データスキーマ

パイプラインは、以下の主要な列を持つテーブルを生成します。

- `law_id`, `law_num`, `law_title`
- `unique_anchor`, `anchor`
- `content`, `article_summary`
- `promulgate_date`, `load_timestamp` など

### `run_bq_pipeline.py`で実行される主要SQL

`{...}`の部分はスクリプトによって動的に置換されます。

**1. DWHテーブルへのマージ (MERGE)**

`unique_anchor`をキーとして、DWHにデータをマージします。
```sql
MERGE `{project_id}.{dataset_id}.dwh_laws` T
USING `{source_table_full_id}` S
ON T.law_id = S.law_id AND T.unique_anchor = S.unique_anchor
WHEN NOT MATCHED THEN
  INSERT (law_id, law_num, law_title, unique_anchor, anchor, content, article_summary, ...)
  VALUES (law_id, law_num, law_title, unique_anchor, anchor, content, article_summary, ...);
```

**2. 法令マスタテーブル (`app_laws_master`) の作成**

`DENSE_RANK`で最新の法令バージョンを特定し、そのユニークな法令タイトルに対してEmbeddingを生成します。
```sql
CREATE OR REPLACE TABLE `{project_id}.{dataset_id}.app_laws_master` AS
WITH RankedLaws AS (
  SELECT
      law_num,
      law_title,
      promulgate_date,
      load_timestamp,
      DENSE_RANK() OVER(PARTITION BY law_num ORDER BY promulgate_date DESC, load_timestamp DESC) as rnk
  FROM
      `{project_id}.{dataset_id}.dwh_laws`
),
LatestLawTitles AS (
    SELECT DISTINCT
        law_num,
        law_title,
        promulgate_date
    FROM RankedLaws
    WHERE rnk = 1
)
SELECT
  ll.law_num,
  ll.law_title,
  ll.promulgate_date,
  e.ml_generate_embedding_result as law_title_embedding
FROM
  LatestLawTitles ll
JOIN
  ML.GENERATE_EMBEDDING(
    MODEL `{project_id}.{dataset_id}.embedding_model`,
    (SELECT law_title AS content FROM LatestLawTitles),
    STRUCT(TRUE AS flatten_json_output)
  ) e ON ll.law_title = e.content;
```

**3. チャンク参照用テーブル (`app_laws_for_indexing`) の作成**

`unique_anchor`ごとに最新版を1つだけ選択し、APIが参照する最終的なテーブルを作成します。
```sql
CREATE OR REPLACE TABLE `{project_id}.{dataset_id}.app_laws_for_indexing` AS
WITH RankedArticles AS (
  -- For each unique article, find the one from the most recent law version
  SELECT
    *,
    ROW_NUMBER() OVER(PARTITION BY unique_anchor ORDER BY promulgate_date DESC, law_id DESC) as rn
  FROM
    `{project_id}.{dataset_id}.dwh_laws`
)
SELECT
  * EXCEPT(rn)
FROM
  RankedArticles
WHERE
  rn = 1;
```