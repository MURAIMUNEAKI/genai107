import os
import sys

from google.cloud import bigquery
from google.cloud.exceptions import NotFound


def run_pipeline(project_id, dataset_id, source_table_full_id, region, connection_name):
    client = bigquery.Client(project=project_id, location=region)
    dataset_ref = client.dataset(dataset_id)

    # スクリプトの場所を基準にsqlディレクトリのパスを取得
    script_dir = os.path.dirname(os.path.abspath(__file__))
    sql_dir = os.path.join(script_dir, "sql")

    print(
        f"--- Starting BigQuery pipeline for project '{project_id}' and dataset '{dataset_id}' in region '{region}' ---",
        file=sys.stderr,
    )

    # --- Step 0: Initial Setup (DWH Table and BQML Model) ---
    # このセットアップ部分は変更なし
    print("--- Checking for DWH table and BQML model... ---", file=sys.stderr)
    dwh_table_id = "dwh_laws"
    dwh_table_ref = dataset_ref.table(dwh_table_id)
    try:
        client.get_table(dwh_table_ref)
        print(f"INFO: DWH table '{dwh_table_id}' already exists.", file=sys.stderr)
    except NotFound:
        print(f"INFO: DWH table '{dwh_table_id}' not found. Creating it now...", file=sys.stderr)
        dwh_create_sql = f"""
        CREATE TABLE `{project_id}.{dataset_id}.{dwh_table_id}` (
          law_id STRING, law_num STRING, law_title STRING, unique_anchor STRING, anchor STRING,
          content STRING, article_summary STRING, era STRING, year INT64, law_type STRING,
          promulgate_date DATE, load_timestamp TIMESTAMP
        );
        """
        client.query(dwh_create_sql).result()
        print(f"SUCCESS: DWH table '{dwh_table_id}' created.", file=sys.stderr)

    model_id = "embedding_model"
    model_ref = dataset_ref.model(model_id)
    try:
        client.get_model(model_ref)
        print(
            f"INFO: BQML model '{model_id}' already exists. It will be replaced to ensure it is up-to-date.",
            file=sys.stderr,
        )
    except NotFound:
        print(f"INFO: BQML model '{model_id}' not found. Creating it now...", file=sys.stderr)

    model_create_sql = f"""
    CREATE OR REPLACE MODEL `{project_id}.{dataset_id}.{model_id}`
    REMOTE WITH CONNECTION `{project_id}.{region}.{connection_name}`
    OPTIONS (endpoint = 'gemini-embedding-001');
    """
    client.query(model_create_sql).result()
    print(
        f"SUCCESS: BQML model '{model_id}' is up-to-date with the latest endpoint.", file=sys.stderr
    )
    print("--- Initial setup check complete. Starting main pipeline... ---", file=sys.stderr)
    print(f"Using source table: {source_table_full_id}", file=sys.stderr)

    # --- .sqlファイルからクエリを読み込み、フォーマットして実行 ---

    # 1. DWHマージ
    print("Executing: DWH Merge from 01_update_dwh.sql...", file=sys.stderr)
    with open(os.path.join(sql_dir, "01_update_dwh.sql"), encoding="utf-8") as f:
        merge_sql = f.read().format(
            project_id=project_id, dataset_id=dataset_id, source_table_full_id=source_table_full_id
        )
    client.query(merge_sql).result()
    print("SUCCESS: DWH Merge completed.", file=sys.stderr)

    # 2. App層の再構築
    print("Executing: App Layer Rebuild from 02_rebuild_app_layer.sql...", file=sys.stderr)
    with open(os.path.join(sql_dir, "02_rebuild_app_layer.sql"), encoding="utf-8") as f:
        app_layer_sql_template = f.read()

    # ファイル内のSQL文をセミコロンで分割
    sql_statements = [stmt for stmt in app_layer_sql_template.split(";") if stmt.strip()]

    for i, statement_template in enumerate(sql_statements):
        print(
            f"  - Executing App Layer statement {i + 1}/{len(sql_statements)}...", file=sys.stderr
        )
        statement = statement_template.format(project_id=project_id, dataset_id=dataset_id)
        client.query(statement).result()
    print("SUCCESS: App Layer Rebuild completed.", file=sys.stderr)

    print("\nPipeline completed successfully!", file=sys.stderr)


if __name__ == "__main__":
    if len(sys.argv) != 6:
        print(
            "Usage: python3 run_bq_pipeline.py <project_id> <dataset_id> <full_source_table_id> <region> <connection_name>",
            file=sys.stderr,
        )
        sys.exit(1)

    project_id = sys.argv[1]
    dataset_id = sys.argv[2]
    source_table_full_id = sys.argv[3]
    region = sys.argv[4]
    connection_name = sys.argv[5]

    parts = source_table_full_id.split(".")
    if len(parts) != 3:
        print(
            f"Error: Invalid <full_source_table_id> format. Expected 'project.dataset.table', but got '{source_table_full_id}'.",
            file=sys.stderr,
        )
        sys.exit(1)

    run_pipeline(project_id, dataset_id, source_table_full_id, region, connection_name)
