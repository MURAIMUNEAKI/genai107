# エンドツーエンド検索テストガイド（条単位チャンキング版）

このガイドは、データパイプラインの全工程が完了した後、最終的な成果物（1行=1条のデータ）が、APIの実際の検索ロジックの各ステップで正しく機能するかを検証するためのテストクエリを記載します。

---

### テストステップ1：法令名のベクトル検索

**目的:** `app_laws_master`テーブルのベクトルインデックスが正しく機能するかを確認します。このステップはチャンキングの仕様変更による影響を受けません。

**クエリ:**
```sql
-- AIが「デジタル庁」というクエリから推定した、という想定
DECLARE law_names ARRAY<STRING> DEFAULT ['デジタル庁設置法', 'デジタル社会形成基本法'];

SELECT
    base.law_num,
    base.law_title,
    distance AS score
FROM
    VECTOR_SEARCH(
        TABLE `<project_id>.<dataset_id>.app_laws_master`,
        'law_title_embedding',
        (
            SELECT ml_generate_embedding_result
            FROM ML.GENERATE_EMBEDDING(
                MODEL `<project_id>.<dataset_id>.embedding_model`,
                (SELECT * FROM UNNEST(law_names) AS content)
            )
        ),
        top_k => 10,
        distance_type => 'COSINE'
    );
```

**期待する結果:**
- `distance`が小さい順に、類似する法令名がリストアップされます。
- このクエリが正常に値を返せば、ベクトル検索のコア機能は正常です。

---

### テストステップ2：条単位データの取得

**目的:** ステップ1で取得した法令番号を使い、`app_laws_for_indexing`テーブルから、条単位に集約されたデータを取得できるか確認します。

**クエリ:**
```sql
-- ステップ1の結果から、最も関連性の高かった法令番号を指定
DECLARE law_nums ARRAY<STRING> DEFAULT ['令和三年法律第三十六号']; -- デジタル庁設置法の法令番号の例

SELECT
    law_title,
    unique_anchor, -- 一意キー
    anchor,        -- e-Govリンク用アンカー (本則の条以外はNULL)
    article_summary,
    SUBSTR(content, 0, 100) AS content_snippet -- contentが条全体のテキストなので、冒頭100文字をスニペットとして表示
FROM `<project_id>.<dataset_id>.app_laws_for_indexing`
WHERE law_num IN UNNEST(law_nums)
ORDER BY unique_anchor;
```

**期待する結果:**
- 指定した法令の全条文が、1行1条の形式で表示されます。
- `unique_anchor`には`Main_Article_1`や`Suppl_Article_1`のような一意なIDが表示されます。
- `anchor`には`Mp-At_1`のようなe-Gov仕様のID、または`NULL`が表示されます。
- `content_snippet`に、各条の冒頭テキストが表示されます。

---

### テストステップ3：特定の条の全文取得

**目的:** `unique_anchor`をキーとして、特定の条の全文とe-Govリンクを正確に取得できるかを確認します。

**クエリ:**
```sql
-- ステップ2の結果から、特定の条のunique_anchorを指定
DECLARE target_law_num STRING DEFAULT '令和三年法律第三十六号';
DECLARE target_unique_anchor STRING DEFAULT 'Main_Article_1'; -- 本則第一条の例

SELECT
    law_id,
    law_title,
    content, -- 条文の全文
    unique_anchor,
    'https://elaws.e-gov.go.jp/search/elawsSearch/elaws_search/lsg0500/detail?lawId=' || law_id || '#' || anchor AS url
FROM `<project_id>.<dataset_id>.app_laws_for_indexing`
WHERE law_num = target_law_num AND unique_anchor = target_unique_anchor;
```

**期待する結果:**
- 指定した条（本則第一条）の全文（`content`）と、e-Gov法令検索へのリンク（`url`）が正しく1行だけ取得できます。
- `url`の末尾には、`#Mp-At_1`のようなアンカーが付与されている（または、本則の条でなければ`#`以降が`null`）ことを確認します。

---

以上の3つのテストがすべて成功すれば、新しい「条単位チャンキング」のパイプラインはAPIの要求通りに正しく機能していると判断できます。

---

## パイプライン全体の最終データ検証クエリ

パイプラインの全処理が完了した後、`app_laws_for_indexing`テーブルにデータが正しく格納されたかを確認するには、以下のクエリを使用します。

特定の法令について、本文（Main）と附則（Suppl）の条文がそれぞれ期待される件数だけ存在するかを確認できます。

### 検証クエリ

```sql
-- app_laws_for_indexing テーブルの件数確認クエリ
-- 【使い方】: 'ここに確認したい法令番号を入れる' の部分を、対象の法令番号に書き換えて実行してください。

WITH law_data AS (
  SELECT
    -- 'Main_Article_1' のような形式から 'Main' または 'Suppl' を抽出
    SUBSTR(unique_anchor, 0, STRPOS(unique_anchor, '_Article_') - 1) AS provision_type
  FROM
    `<project_id>.<dataset_id>.app_laws_for_indexing` -- 対象テーブル
  WHERE
    law_num = 'ここに確認したい法令番号を入れる' -- 例: '令和三年法律第三十六号'
)
SELECT
  provision_type,
  COUNT(*) AS article_count
FROM
  law_data
GROUP BY
  provision_type;
```

### 「デジタル庁設置法」の場合の期待結果

-   **law_num:** `令和三年法律第三十六号`
-   **期待される結果:**
    -   `Main`: 18
    -   `Suppl`: 4
