variable "api_name" {
  description = "Name of the API"
  type        = string
}

variable "project_id" {
  description = "Google Cloud Project ID"
  type        = string
}

variable "allowed_ips" {
  description = "List of allowed IP addresses"
  type        = list(string)
}

variable "genai_api_service" {
  description = "generative AI api "
  type        = string
}

variable "api_id" {
  description = "ID of the API Gateway API"
  type        = string
}

variable "api_config_id" {
  description = "ID of the API Gateway API Config"
  type        = string
}