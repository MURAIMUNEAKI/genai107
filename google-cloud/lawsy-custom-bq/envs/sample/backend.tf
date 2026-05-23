terraform {
  backend "gcs" {
    bucket = "YOUR_TFSTATE_BUCKET"
    prefix = "terraform/state/lawsy-custom"
  }
}
