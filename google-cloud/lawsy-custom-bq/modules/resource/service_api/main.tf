
resource "google_project_service" "apis" {
  for_each = toset(var.services)

  service                    = each.value
  project                    = var.project.id
  disable_dependent_services = var.apis.disable_dependent_services
  disable_on_destroy         = var.apis.disable_on_destroy
}