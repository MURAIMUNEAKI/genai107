### BigQuery データモデル仕様書

#### 1. 概要
本ドキュメントは、`lawsy-custom-bq`プロジェクトで利用するBigQueryのデータ構造を定義します。データは**Source層, DWH層, App層**の3層アーキテクチャで管理されます。

検索戦略では、まず`App層`の**法令マスタ** (`app_laws_master`) を使って関連性の高い法令を特定し、次に**チャンクテーブル** (`app_laws_for_indexing`) を使ってその法令内の具体的な条項へと絞り込みます。

---

#### 2. Source層: `source_laws_[YYYYMMDD]`
未加工のXMLから抽出したデータを、処理実行日ごとのテーブルに格納する層。APIからは直接利用されません。

*   **スキーマ (Schema)**
    | カラム名 | 型 | 説明 |
    | :--- | :--- | :--- |
    | `law_id` | STRING | e-Gov法令APIにおける一意のID（版管理を含む）。 |
    | `law_num` | STRING | 法令番号（例: `平成二十五年法律第二十七号`）。 |
    | `law_title` | STRING | 法令の正式名称。`<LawTitle>`タグから抽出。 |
    | `article_caption` | STRING | チャンクが属する条の`<ArticleCaption>`（条見出し）の全文。存在しない場合はNULL。 |
    | `first_paragraph_text` | STRING | チャンクが属する条の最初の`<ParagraphSentence>`の全文。存在しない場合はNULL。 |
    | `content` | STRING | チャンクの全文テキスト。 |
    | ... (era, year, promulgate_date, title, anchorなどその他メタデータ) | ... | |

*   **作成方法 (Creation Process)**
    *   **スクリプト**: `preprocess/load_to_bq.py`
    *   **プロセス**: XMLをパースし、上記スキーマに沿ったフィールドを抽出してJSONLとして出力し、BigQueryにロードする。

*   **利用方法 (Usage)**
    *   DWH層を構築するための元データ。各データ処理バッチの履歴アーカイブ。

---

#### 3. DWH層: `dwh_laws_embeddings`
全バージョンの法令データとembeddingベクトルを履歴として蓄積するマスターデータ層。APIからは直接利用されません。

*   **スキーマ (Schema)**
    *   Source層のスキーマに`content_embedding` (VECTOR) カラムを追加したもの。

*   **作成方法 (Creation Process)**
    *   **スクリプト**: `preprocess/sql/01_update_dwh.sql`
    *   **プロセス**: 最新のSource層テーブルから新規・更新分を特定し、`content`のembeddingを生成してDWHテーブルに**追記（INSERT）**する。

*   **利用方法 (Usage)**
    *   App層のテーブル群を構築するための唯一のデータソース。

---

#### 4. App層: `app_laws_master`
APIが**法令名**で検索するために利用する、最新版の法令マスタテーブル。

*   **スキーマ (Schema)**
    | カラム名 | 型 | 説明 |
    | :--- | :--- | :--- |
    | `law_num` | STRING | 法令番号（主キー）。 |
    | `law_title` | STRING | 法令の正式名称。 |
    | `promulgate_date` | DATE | 公布日（最新版の判定に使用）。 |
    | `law_title_embedding` | VECTOR | `law_title`のembeddingベクトル。**このカラムにベクトルインデックスを作成する。** |

*   **作成方法 (Creation Process)**
    *   **スクリプト**: `preprocess/sql/02_rebuild_app_layer.sql`
    *   **プロセス**: DWH層から最新版の法令のみを抽出し、`law_title`のembeddingを生成してテーブルを**再作成（CREATE OR REPLACE TABLE）**する。

*   **利用方法 (Usage)**
    *   検索ステップ「法令名検索」で利用。

---

#### 5. App層: `app_laws_for_indexing`
APIが**条項の概要取得**や**全文取得**に利用する、最新版のチャンクデータテーブル。

*   **スキーマ (Schema)**
    | カラム名 | 型 | 説明 |
    | :--- | :--- | :--- |
    | `law_num` | STRING | |
    | `law_title` | STRING | |
    | `article_summary`| STRING | `COALESCE(article_caption, SUBSTR(first_paragraph_text, 1, 40))` で生成される条の概要。 |
    | `content` | STRING | |
    | `content_embedding`| VECTOR | `content`のembeddingベクトル。**このカラムにベクトルインデックスを作成する。** |
    | ... (law_id, promulgate_date, anchorなどその他メタデータ) | ... | |

*   **作成方法 (Creation Process)**
    *   **スクリプト**: `preprocess/sql/02_rebuild_app_layer.sql`
    *   **プロセス**: DWH層から最新版のチャンクのみを抽出し、`article_summary`を動的に生成してテーブルを**再作成（CREATE OR REPLACE TABLE）**する。

*   **利用方法 (Usage)**
    *   検索ステップ「条項概要の取得」「条項全文の取得」で利用。
