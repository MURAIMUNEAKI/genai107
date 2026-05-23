from dataclasses import dataclass, field

# Constants
CITATIONS = "citations"
TYPE = "type"
TEXT = "text"
RETRIEVED_REFERENCES = "retrievedReferences"
METADATA = "metadata"
FILE_NAME = "file_name"
URL = "url"
PAGE_NUMBER = "x-amz-bedrock-kb-document-page-number"


@dataclass
class Metadata:
    file_name: str | None = None
    url: str | None = None
    page_number: int | None = None


@dataclass
class Citation:
    text: str
    metadata: list[Metadata] = field(default_factory=list)
    relevance_rating: int = 0


@dataclass
class KBResponse:
    citations: list[Citation] = field(default_factory=list)


def process_kb_response(response: dict) -> KBResponse:
    kb_response = KBResponse()

    if CITATIONS in response:
        for citation in response[CITATIONS]:
            text = citation.get("generatedResponsePart", {}).get("textResponsePart", {}).get("text", "")
            citation_obj = Citation(text=text)

            if RETRIEVED_REFERENCES in citation:
                for reference in citation[RETRIEVED_REFERENCES]:
                    metadata = Metadata()
                    if METADATA in reference:
                        metadata.file_name = reference[METADATA].get(FILE_NAME)
                        metadata.url = reference[METADATA].get(URL)
                        page_number = reference[METADATA].get(PAGE_NUMBER)
                        metadata.page_number = int(page_number) if page_number is not None else None

                    citation_obj.metadata.append(metadata)

            kb_response.citations.append(citation_obj)

    return kb_response


def extract_texts_from_kb_response(kb_response: KBResponse) -> list[str]:
    return [citation.text for citation in kb_response.citations]
