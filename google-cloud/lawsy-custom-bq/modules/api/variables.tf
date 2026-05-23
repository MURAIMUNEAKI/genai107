variable "project_id" {
  description = "The ID of the Google Cloud project where the API Gateway will be created."
  type        = string
}

variable "project_number" {
  description = "The number of the Google Cloud project where the API Gateway will be created."
  type        = string
}

variable "location" {
  description = "The region where the API Gateway will be deployed."
  type        = string
  default     = "asia-northeast1"
}

variable "service_account_id" {
  description = "The id of the service account that will be used by the API Gateway to invoke backend function"
  type        = string
  default     = "apig-function-invoke"
  validation {
    condition     = can(regex("^[a-z](?:[-a-z0-9]{4,28}[a-z0-9])$", var.service_account_id))
    error_message = "The service_account_id must be 6-30 characters long, start with a lowercase letter, and only contain lowercase letters, numbers, and hyphens."
  }
}

variable "api_name" {
  description = "The name of the API Gateway"
  type        = string
  default     = "Sample API"
}

variable "api_id" {
  description = "The ID of the API Gateway"
  type        = string
  default     = "sample-api"
  validation {
    condition     = can(regex("^[a-z](?:[-a-z0-9]{4,28}[a-z0-9])$", var.api_id))
    error_message = "The api_id must be 6-30 characters long, start with a lowercase letter, and only contain lowercase letters, numbers, and hyphens."
  }
}

variable "dataset_id" {
  description = "The ID of the BigQuery dataset."
  type        = string
}

variable "gemini_settings" {
  type = map(any)
  description = "Settings for Gemini API"
  default = {}
}

