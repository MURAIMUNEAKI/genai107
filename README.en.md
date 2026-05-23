[日本語](README.md) | English

# GENAI AI Applications

## Overview

GENAI (derived from "Gennai," named after Hiraga Gennai, an 18th-century Japanese inventor) is a generative AI platform developed and operated by the Digital Agency, Government of Japan. It provides a secure, fast, and user-friendly environment for government officials to access task-specific generative AI applications.  
GENAI consists of two main systems:

- [GENAI Web](https://github.com/digital-go-jp/genai-web): The web application interface for end users
- [AI Applications for Government Operations](https://github.com/digital-go-jp/genai-ai-api): Microservices powered by generative AI

This repository contains a subset of the AI applications actually deployed in central government ministries.  
For more details about GENAI's initiatives, vision, and AI utilization strategies, please visit [Digital Agency's Tech Blog](https://digital-gov.note.jp/m/m90208c3610d0) (Japanese).

## Published AI Applications

AI applications for government operations can be built in environments independent of GENAI Web, as long as they comply with the protocol between the applications and GENAI Web. These applications can then be registered to GENAI through GUI operations.  
Below is a summary of the AI applications published in this repository, organized by cloud service providers adopted by the Government Cloud.

### Microsoft Azure

- [Development template for AI applications based on self-deployed LLMs](./azure/genai-azure/README.en.md)

### Google Cloud

- [Reproducible implementation of an AI application that responds to legal systems by referring to legislation data retrieved from the e-Gov law search](./google-cloud/lawsy-custom-bq/README.en.md)

### Amazon Web Services

- [Development template for RAG-based AI applications for administrative use](./aws/query-expansion-rag/README.en.md)

For details about each AI application, please refer to the README files in their respective folders.

## Issue / Pull Request Policy

This repository accepts issue reports only for critical problems that affect the stable operation of services. We do not accept pull requests.

### Issues

#### What to report

- Bugs that cause data loss or corruption
- Failures that make services unavailable
- Issues related to legal or regulatory violations (e.g., unintended exposure of personal information)

#### What not to report

Please refrain from reporting the following as issues.  
Issues that do not match the template may be closed.

- Feature requests or suggestions
- Minor display issues or typos
- Performance improvement suggestions
- Coding style comments
- Questions or usage inquiries

### Response policy

- Issues will be addressed based on internal priority assessment
- We cannot guarantee that all issues will be addressed
- We do not provide individual responses to inquiries about issue status
- For issues deemed critical, we will provide status updates on the issue page when possible

## Vulnerability Reporting

To report security vulnerabilities, please visit https://github.com/digital-go-jp/genai-ai-api/security.

## Nature of This Repository

This repository (source code and documentation) is created and published by the Digital Agency, Government of Japan.  
As a public resource, it is openly available to all members of the OSS community. Therefore, the following actions are prohibited:

- Actions that support or exclude specific ideologies, organizations, or companies
- Political, religious, or discriminatory statements
- Handling personal information or sensitive information in the repository
- Disclosing vulnerability details to third parties without prior reporting and approval from the Digital Agency when security vulnerabilities are discovered
- Modifying the source code for the purpose of attacking other systems

## Related Links

- [Introducing Government AI, Project "GENAI" (Gennai) - Digital Agency note article](https://digital-gov.note.jp/n/ndc07326b7491) (Japanese)

## License

- Software: Licensed under the [MIT License](LICENSE).
- Documentation: Licensed under the [Creative Commons Attribution 4.0 International License](LICENSE-CC-BY) (CC BY 4.0).
