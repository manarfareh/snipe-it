terraform {
  required_version = ">= 1.5.0"

  required_providers {
    docker = {
      source  = "kreuzwerker/docker"
      version = "~> 3.0"
    }
  }

  # Uncomment to use remote state (e.g. Terraform Cloud or S3)
  # backend "s3" {
  #   bucket = "snipeit-tfstate"
  #   key    = "snipeit/terraform.tfstate"
  #   region = "us-east-1"
  # }
}

provider "docker" {
  # Defaults to DOCKER_HOST env var or unix:///var/run/docker.sock
}

# ── Network ──────────────────────────────────────────────────────────────────
resource "docker_network" "snipeit" {
  name   = "snipe-it_default"
  driver = "bridge"
}

# ── Volumes ───────────────────────────────────────────────────────────────────
resource "docker_volume" "db_data" {
  name = "snipeit_db_data"
}

resource "docker_volume" "app_storage" {
  name = "snipeit_app_storage"
}

resource "docker_volume" "prometheus_data" {
  name = "snipeit_prometheus_data"
}

resource "docker_volume" "grafana_data" {
  name = "snipeit_grafana_data"
}

resource "docker_volume" "loki_data" {
  name = "snipeit_loki_data"
}

# ── Database: MariaDB ─────────────────────────────────────────────────────────
resource "docker_image" "mariadb" {
  name = "mariadb:11.4.7"
}

resource "docker_container" "db" {
  name    = "snipeit_db"
  image   = docker_image.mariadb.image_id
  restart = "unless-stopped"

  env = [
    "MYSQL_DATABASE=${var.db_name}",
    "MYSQL_USER=${var.db_user}",
    "MYSQL_PASSWORD=${var.db_password}",
    "MYSQL_ROOT_PASSWORD=${var.db_root_password}",
  ]

  volumes {
    volume_name    = docker_volume.db_data.name
    container_path = "/var/lib/mysql"
  }

  healthcheck {
    test         = ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
    interval     = "5s"
    timeout      = "1s"
    retries      = 5
    start_period = "30s"
  }

  networks_advanced {
    name = docker_network.snipeit.name
  }
}

# ── Application: Snipe-IT ─────────────────────────────────────────────────────
resource "docker_image" "snipeit" {
  name = "snipe/snipe-it:${var.app_version}"
}

resource "docker_container" "app" {
  name    = "snipeit_app"
  image   = docker_image.snipeit.image_id
  restart = "unless-stopped"

  ports {
    internal = 80
    external = var.app_port
  }

  volumes {
    volume_name    = docker_volume.app_storage.name
    container_path = "/var/lib/snipeit"
  }

  env = [
    "APP_URL=http://localhost:${var.app_port}",
    "APP_ENV=${var.app_env}",
    "DB_CONNECTION=mysql",
    "DB_HOST=snipeit_db",
    "DB_DATABASE=${var.db_name}",
    "DB_USERNAME=${var.db_user}",
    "DB_PASSWORD=${var.db_password}",
  ]

  depends_on = [docker_container.db]

  networks_advanced {
    name = docker_network.snipeit.name
  }
}

# ── Prometheus ────────────────────────────────────────────────────────────────
resource "docker_image" "prometheus" {
  name = "prom/prometheus:v2.51.0"
}

resource "docker_container" "prometheus" {
  name    = "snipeit_prometheus"
  image   = docker_image.prometheus.image_id
  restart = "unless-stopped"

  command = [
    "--config.file=/etc/prometheus/prometheus.yml",
    "--storage.tsdb.path=/prometheus",
    "--storage.tsdb.retention.time=${var.prometheus_retention}",
    "--web.enable-lifecycle",
  ]

  ports {
    internal = 9090
    external = 9090
  }

  volumes {
    host_path      = abspath("${path.module}/../../observability/prometheus/prometheus.yml")
    container_path = "/etc/prometheus/prometheus.yml"
    read_only      = true
  }

  volumes {
    host_path      = abspath("${path.module}/../../observability/prometheus/alerts.yml")
    container_path = "/etc/prometheus/alerts.yml"
    read_only      = true
  }

  volumes {
    volume_name    = docker_volume.prometheus_data.name
    container_path = "/prometheus"
  }

  networks_advanced {
    name = docker_network.snipeit.name
  }
}

# ── Grafana ───────────────────────────────────────────────────────────────────
resource "docker_image" "grafana" {
  name = "grafana/grafana:10.4.0"
}

resource "docker_container" "grafana" {
  name    = "snipeit_grafana"
  image   = docker_image.grafana.image_id
  restart = "unless-stopped"

  ports {
    internal = 3000
    external = 3000
  }

  env = [
    "GF_SECURITY_ADMIN_USER=admin",
    "GF_SECURITY_ADMIN_PASSWORD=${var.grafana_password}",
    "GF_USERS_ALLOW_SIGN_UP=false",
  ]

  volumes {
    host_path      = abspath("${path.module}/../../observability/grafana/provisioning")
    container_path = "/etc/grafana/provisioning"
    read_only      = true
  }

  volumes {
    volume_name    = docker_volume.grafana_data.name
    container_path = "/var/lib/grafana"
  }

  depends_on = [docker_container.prometheus]

  networks_advanced {
    name = docker_network.snipeit.name
  }
}
