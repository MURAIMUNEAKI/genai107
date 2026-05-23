-- App層のテーブル群 (`app_laws_master`, `app_laws_for_indexing`) を再構築するクエリ。
-- 注意: 複数のSQL文が含まれています。BigQueryコンソールで一度に実行するか、
--      セミコロンで区切って順番に実行してください。

-- ステップ1: 法令マスタテーブル (`app_laws_master`) の作成
CREATE OR REPLACE TABLE `{project_id}.{dataset_id}.app_laws_master` AS
WITH RankedLaws AS (
  -- First, rank all DWH rows to find the latest version for each law_num
  SELECT
      law_num,
      law_title,
      promulgate_date,
      -- Use load_timestamp as a tie-breaker for robustness
      DENSE_RANK() OVER(PARTITION BY law_num ORDER BY promulgate_date DESC, load_timestamp DESC) as rnk
  FROM
      `{project_id}.{dataset_id}.dwh_laws`
),
LatestLawTitles AS (
    -- Then, select the unique titles from only the latest versions (rnk=1)
    SELECT DISTINCT
        law_num,
        law_title,
        promulgate_date
    FROM RankedLaws
    WHERE rnk = 1
)
-- Finally, generate embeddings for these unique, latest titles
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


-- ステップ2: チャンク参照用テーブル (`app_laws_for_indexing`) の作成
CREATE OR REPLACE TABLE `{project_id}.{dataset_id}.app_laws_for_indexing` AS
WITH RankedArticles AS (
  -- For each unique article within each law, find the one from the most recent law version
  SELECT
    *,
    ROW_NUMBER() OVER(PARTITION BY law_num, unique_anchor ORDER BY promulgate_date DESC, law_id DESC) as rn
  FROM
    `{project_id}.{dataset_id}.dwh_laws`
)
SELECT
  * EXCEPT(rn)
FROM
  RankedArticles
WHERE
  rn = 1;


-- ステップ3: ベクトルインデックスの再作成
CREATE OR REPLACE VECTOR INDEX `laws_master_index`
ON `{project_id}.{dataset_id}.app_laws_master`(law_title_embedding)
OPTIONS(
  index_type = 'IVF',
  distance_type = 'COSINE',
  ivf_options = '{{"num_lists":10}}'
);