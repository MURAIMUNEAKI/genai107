# add_metadata_json

Bedrock Knowledge Base にドキュメントを登録する際に必要なメタデータ JSON ファイルを生成するスクリプト群です。

## 概要

Bedrock Knowledge Base の S3 データソースにドキュメントをアップロードする際、ファイル名と URL の対応情報をメタデータ JSON として付与することで、RAG の引用情報にリンクを含めることができます。

## 手順

1. ドキュメントを任意のディレクトリに格納する
2. `py/01_write_filepath.py --dir <ディレクトリパス>` を実行して、対象ファイル名の一覧を取得する
3. ファイル名一覧を Excel ファイル（`URL対応表.xlsx`）にコピーする。ファイルに対応する URL をそれぞれ "URL" 列に記入する
4. `py/02_add_metadata_json.py --dir <ディレクトリパス> --excel <Excelファイルパス>` を実行して、`metadata.json` ファイルを一括作成する

## スクリプト

### `py/01_write_filepath.py`

指定ディレクトリ配下の全ファイル名を標準出力に出力します。メタデータ付与対象ファイルの確認に使用します。

**使用方法**:

```bash
python py/01_write_filepath.py --dir /path/to/documents
```

**引数**:

| 引数 | 必須 | 説明 |
|------|------|------|
| `--dir` | ✅ | ドキュメントが格納されているディレクトリのパス |

---

### `py/02_add_metadata_json.py`

指定ディレクトリ配下のドキュメント (`.docx`, `.pdf`, `.xlsx`, `.doc`) に対して、Excel ファイルの URL 対応表を参照しながらメタデータ JSON ファイルを生成します。

**使用方法**:

```bash
python py/02_add_metadata_json.py --dir /path/to/documents --excel /path/to/url_mapping.xlsx
```

**使用方法（S3 Vectors バックエンドの場合）**:

```bash
python py/02_add_metadata_json.py --dir /path/to/documents --excel /path/to/url_mapping.xlsx --s3vectors
```

**引数**:

| 引数 | 必須 | 説明 |
|------|------|------|
| `--dir` | ✅ | ドキュメントが格納されているディレクトリのパス |
| `--excel` | ✅ | ファイル名と URL の対応表が記載された Excel ファイル (.xlsx) のパス |
| `--s3vectors` | ❌ | S3 Vectors バックエンド向けの metadata サイズ検証を有効化。metadata が 1KB (1024 バイト) を超える場合に警告を標準出力に表示する（処理は継続） |

> **S3 Vectors を使用する場合の注意**: S3 Vectors バックエンドの Bedrock Knowledge Base では、metadata の合計サイズに 1KB の上限があります。Bedrock が内部的に付与する `AMAZON_BEDROCK_TEXT` や `AMAZON_BEDROCK_METADATA` フィールドもサイズに含まれるため、ユーザー定義の metadata（`file_name`・`url`）は可能な限り短く保つことを推奨します。`--s3vectors` フラグを使用すると、サイズが上限を超えるリスクがあるファイルを事前に検出できます。

**Excel ファイルの形式**:

| ファイル名 | URL |
|------------|-----|
| document1.pdf | https://example.com/document1 |
| document2.docx | https://example.com/document2 |

**生成されるメタデータ JSON の形式**:

```json
{
    "metadataAttributes": {
        "file_name": "document1.pdf",
        "url": "https://example.com/document1"
    }
}
```

生成されたメタデータ JSON は、元のドキュメントファイルと同じ場所に `<ファイル名>.metadata.json` として保存されます。

## 必要なライブラリ

```bash
pip install pandas openpyxl
```
