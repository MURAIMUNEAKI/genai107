variable "project" {
  type = object({
    id     = string
    number = string
  })
}

variable "apis" {
  type = object({
    disable_dependent_services = bool
    disable_on_destroy         = bool
  })
}

variable "services" {
  type        = list(string)
  description = "List of Google Cloud APIs to enable"
  default = [
    "storage-component.googleapis.com",
    "apigateway.googleapis.com",
    "servicemanagement.googleapis.com",
    "servicecontrol.googleapis.com",
    "cloudfunctions.googleapis.com",
    "cloudbuild.googleapis.com",
    "aiplatform.googleapis.com",
    "apikeys.googleapis.com",
  ]
}
