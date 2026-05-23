-- DWH層のテーブル (`dwh_laws`) を更新するクエリ
-- 新しいSourceテーブルのデータをDWHにマージします。
-- ON句で `unique_anchor` を使い、条文ごとに一意性を担保します。

MERGE `{project_id}.{dataset_id}.dwh_laws` T
USING `{source_table_full_id}` S
ON T.law_id = S.law_id AND T.unique_anchor = S.unique_anchor
WHEN MATCHED AND T.content <> S.content THEN
  UPDATE SET
    T.law_num = S.law_num,
    T.law_title = S.law_title,
    T.anchor = S.anchor,
    T.content = S.content,
    T.article_summary = S.article_summary,
    T.era = S.era,
    T.year = S.year,
    T.law_type = S.law_type,
    T.promulgate_date = S.promulgate_date,
    T.load_timestamp = CURRENT_TIMESTAMP()
WHEN NOT MATCHED THEN
  INSERT (
    law_id, law_num, law_title, unique_anchor, anchor, content, article_summary,
    era, year, law_type, promulgate_date, load_timestamp
  )
  VALUES (
    S.law_id, S.law_num, S.law_title, S.unique_anchor, S.anchor, S.content, S.article_summary,
    S.era, S.year, S.law_type, S.promulgate_date, CURRENT_TIMESTAMP()
  );
