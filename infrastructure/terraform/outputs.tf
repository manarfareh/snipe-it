output "app_url" {
  description = "URL to access the Snipe-IT application"
  value       = "http://localhost:${var.app_port}"
}

output "grafana_url" {
  description = "URL to access Grafana dashboards"
  value       = "http://localhost:3000"
}

output "prometheus_url" {
  description = "URL to access Prometheus"
  value       = "http://localhost:9090"
}

output "db_container_name" {
  description = "Name of the database container"
  value       = docker_container.db.name
}

output "app_container_name" {
  description = "Name of the application container"
  value       = docker_container.app.name
}
