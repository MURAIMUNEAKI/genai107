from services.kb_response_processor import KBResponse


def generate_reference(kb_response: KBResponse) -> str:
    if not kb_response.citations:
        return ""

    # references = '<details>\n<summary>リファレンス</summary>\n\n'
    references = ""

    for i, citation in enumerate(kb_response.citations, 1):
        references += f"### 参考情報 {i}\n\n"
        references += f"#### 引用文: \n{citation.text}\n"
        references += "#### 引用ファイル: \n"

        # メタデータの処理
        seen_metadata = set()
        for metadata in citation.metadata:
            meta_info = []
            metadata_key = (metadata.file_name, metadata.url, metadata.page_number)
            if metadata_key not in seen_metadata:
                seen_metadata.add(metadata_key)
                if metadata.file_name and metadata.url:
                    meta_info.append(f"[{metadata.file_name}]({metadata.url})")
                if metadata.page_number is not None:
                    meta_info.append(f"p.{metadata.page_number}")

                # 他のメタデータフィールドがあれば、ここに追加
                if meta_info:
                    references += f"- {' | '.join(meta_info)}\n"

        references += "\n"

    # references += '</details>'

    return references
