variable "app_env" {
  description = "Application environment"
  type        = string
  default     = "production"
  validation {
    condition     = contains(["production", "staging", "test"], var.app_env)
    error_message = "app_env must be one of: production, staging, test."
  }
}

variable "app_version" {
  description = "Snipe-IT Docker image tag"
  type        = string
  default     = "latest"
}

variable "app_port" {
  description = "Host port to expose the Snipe-IT application on"
  type        = number
  default     = 8000
}

variable "db_name" {
  description = "MySQL database name"
  type        = string
  default     = "snipeit"
}

variable "db_user" {
  description = "MySQL application user"
  type        = string
  default     = "snipeit"
}

variable "db_password" {
  description = "MySQL application user password"
  type        = string
  sensitive   = true
}

variable "db_root_password" {
  description = "MySQL root password"
  type        = string
  sensitive   = true
}

variable "grafana_password" {
  description = "Grafana admin password"
  type        = string
  sensitive   = true
  default     = "changeme"
}

variable "prometheus_retention" {
  description = "Prometheus data retention duration"
  type        = string
  default     = "15d"
}
