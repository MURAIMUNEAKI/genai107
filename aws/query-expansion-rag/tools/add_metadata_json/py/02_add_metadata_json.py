import argparse
import json
import os

import pandas as pd

# Maximum metadata size (in bytes) for S3 Vectors backend (Bedrock custom metadata limit)
METADATA_LIMIT_BYTES = 1024

parser = argparse.ArgumentParser(description="Add metadata JSON files for Bedrock Knowledge Base")
parser.add_argument("--dir", required=True, help="Path to the document directory")
parser.add_argument("--excel", required=True, help="Path to the URL mapping Excel file (.xlsx)")
parser.add_argument(
    "--s3vectors",
    action="store_true",
    help="Enable S3 Vectors metadata size validation (warn if metadata exceeds 1KB limit)",
)
args = parser.parse_args()

# 対象ドキュメントを格納しているディレクトリパス
tar_dir = args.dir
# ファイル名と共有リンクURLの対応表が記載されているExcelファイルパス
excel_file = args.excel

# Read the Excel file and get the shared link URLs
df_shared_link_urls = pd.read_excel(excel_file)

# Loop through all files in the tar_dir directory
for root, _dirs, files in os.walk(tar_dir):
    for filename in files:
        if (
            filename.endswith(".docx")
            or filename.endswith(".pdf")
            or filename.endswith(".xlsx")
            or filename.endswith(".doc")
        ):
            # Get the PDF file path
            pdf_file = os.path.join(root, filename)
            # Create the JSON file name
            json_file = f"{pdf_file}.metadata.json"
            # Get the shared link URL from the Excel file
            shared_link_url = df_shared_link_urls[df_shared_link_urls["ファイル名"] == filename]["URL"].iloc[0]
            # Create the metadata attributes
            metadata_attributes = {"file_name": filename, "url": shared_link_url}
            # Validate metadata size for S3 Vectors backend
            if args.s3vectors:
                metadata_bytes = len(json.dumps(metadata_attributes, ensure_ascii=False).encode("utf-8"))
                if metadata_bytes > METADATA_LIMIT_BYTES:
                    print(
                        f"WARNING: {filename}: metadata size {metadata_bytes} bytes exceeds "
                        f"{METADATA_LIMIT_BYTES}-byte limit for S3 Vectors"
                    )
            # Create the JSON data
            json_data = {"metadataAttributes": metadata_attributes}
            # Write the JSON data to the file
            with open(json_file, "w", encoding="utf-8") as f:
                json.dump(json_data, f, indent=4, ensure_ascii=False)
