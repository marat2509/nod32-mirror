# nod32mirror

ESET Nod32 Updates Mirror based on nginx:mainline-alpine, alpine:latest and php script [eset_mirror_script](https://github.com/Kingston-kms/eset_mirror_script) with deleted brandings and many improvements

## Setup

Get the docker-compose file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/docker-compose.yml
```

Get the configuration file:

```sh
wget https://raw.githubusercontent.com/marat2509/nod32-mirror/main/nod32-mirror.yaml
```

## Run

```sh
docker-compose up -d
```

## Using

Open the browser and go to `http://localhost:8084/`

If the page is displayed, enter your URL in the ESET settings

## Storage

The worker stores newly downloaded update files in content-addressed storage
under `storage.root` and publishes the mirror tree under `web.root`.

Default config:

```yaml
runtime:
  root: /data
  temp: tmp
  php:
    memory_limit: 512M
state:
  root: data
  database:
    file: content-index.sqlite
  files:
    credentials: keys.json
    database_sizes: databases_size.json
    last_update: lastupdate.json
    gc_state: gc-state.json
    lock: locks/update.lock
  directories:
    debug: debug
web:
  root: www
  reports:
    json:
      enabled: true
      file: index.json
    html:
      enabled: true
      file: index.html
    status:
      enabled: true
      file: status.json
storage:
  root: storage
  directories:
    blobs: blobs
    quarantine: quarantine
  hash: sha256
  link_method: hardlink
  gc:
    enabled: false
    excludes: []
```

Any configuration value can be loaded from an environment variable or a file:

```yaml
runtime:
  php:
    memory_limit: env://PHP_MEMORY_LIMIT
downloads:
  proxy:
    credentials:
      password: file:///run/secrets/proxy_password
eset:
  mirrors:
    hosts: env://ESET_MIRRORS
```

The value must consist entirely of `env://VARIABLE` or `file://path`. Relative
file paths are resolved from the directory containing `nod32-mirror.yaml`.
Trailing CR/LF characters are removed from file values. Boolean values
`true`/`false`, `null`, and inline YAML/JSON arrays or objects are decoded to
their native types; all other values remain strings. An undefined environment
variable, missing file, unreadable file, or malformed inline collection stops
startup with a configuration error. For the example above, `ESET_MIRRORS` can
contain `["update.eset.com", "um01.eset.com"]`.

The Docker example mounts `./docker-data` into the worker as `/data`.
`runtime.root` is the global base directory. Relative values in `runtime.temp`,
`state.root`, `web.root`, `storage.root`, and `logging.root` are resolved from
it. Absolute directory paths are accepted and do not use `runtime.root`.
`state.database.file`, `state.files.*`, and `state.directories.*` are resolved
from `state.root`. `storage.directories.*` is resolved from `storage.root`,
`logging.file.path` from `logging.root`, and report file names from `web.root`.

`link_method` can be `hardlink`, `softlink`, or `copy`. Use `copy` when
`storage` and `www` are separate Docker mounts. `hardlink` requires both paths
to be on the same filesystem. `state.database.file` configures the SQLite
metadata database. With the example configuration it resolves to
`/data/data/content-index.sqlite`. Absolute file paths are also accepted. The
worker creates the SQLite database automatically. `runtime.php.memory_limit`
is applied to the PHP process at startup with `ini_set()`.

All persistent state, storage, and log artifacts have configurable locations.
Relative paths use their owning root; absolute paths bypass it:

- `state.database.file`: SQLite content index.
- `state.files.credentials`: stored credentials.
- `state.files.database_sizes`: cached database sizes.
- `state.files.last_update`: successful update timestamps.
- `state.files.gc_state`: latest garbage-collection result.
- `state.files.lock`: single-instance update lock.
- `state.directories.debug`: downloaded credential-discovery debug pages.
- `storage.directories.blobs`: content-addressed blobs.
- `storage.directories.quarantine`: rejected or corrupt storage files.
- `logging.file.path`: application log file; rotated archives use this path as
  their prefix.

Temporary working files remain under `runtime.temp`. Published ESET update
paths remain under `web.root` and follow the upstream mirror directory layout.

`storage.gc.excludes` contains paths, prefixes, or glob patterns relative to
`web.root` that GC must leave untouched, for example `custom/`, `robots.txt`,
`*.json`, or `.well-known/**`. In globs, `*` matches within one path segment and
`**` matches across directories. Leading `/` or `./` is ignored, so
`/robots.txt`, `./robots.txt`, and `robots.txt` are equivalent.

Report files are configured with `web.reports.json.file`,
`web.reports.html.file`, and `web.reports.status.file`. They are relative to
`web.root`; parent directories are created automatically.

## Mirror discovery

The worker can collect additional ESET update hosts from the `[SERVERS]`
section of `update.ver` files before processing versions:

```ini
[SERVERS]
list=10@//um02.eset.com,10@//185.94.157.10,100000@//update.eset.com
```

The numeric value before `@` is discarded and never affects ordering. Static
`eset.mirrors.hosts` remain the trusted bootstrap pool used to find a working
credential and fetch the initial `update.ver` files.

```yaml
eset:
  mirrors:
    strategy: best
    hosts:
      - update.eset.com
      - um01.eset.com
    discovery:
      enabled: true
      pool: merge
      fetch:
        versions: true
      validation:
        max_hosts: 100
        allow_ports: false
        allow_private_addresses: false
        allowed_hosts:
          - update.eset.com
          - "*.eset.com"
          - "**.example.net"
          - 185.94.157.0/24
```

`pool: merge` combines configured and discovered hosts. `pool: discovered`
uses configured hosts only for bootstrap and as a fallback when discovery
returns no accepted hosts. Discovery has no persistent cache and runs on every
worker execution.

`fetch.versions: true` inspects every enabled version. It can instead contain
an explicit list such as `[ep10, ep13]`. For each version, bootstrap mirrors
are tried in configured order until the first non-empty `update.ver` server
list is downloaded and parsed.

`validation.max_hosts` caps the final deduplicated union. `allow_ports: false`
rejects advertised `host:port` endpoints; when enabled, ports from 1 through
65535 are accepted. `allow_private_addresses: false` rejects literal and
DNS-resolved private, loopback, link-local, and reserved addresses.

`allowed_hosts: null`, an empty list, or an omitted key disables the allowlist.
A non-empty list accepts these rule types:

- `some-domain.com` matches that exact hostname only.
- `*.example.com` matches exactly one subdomain label.
- `**.example.com` matches the base hostname and subdomains of any depth.
- `8.8.8.8` and `2001:4860:4860::8888` match exact IP addresses.
- `185.94.157.0/24` and `2001:4860::/32` match IPv4 or IPv6 networks.

Wildcard tokens are only valid as the complete leftmost label. Discovered
hostnames are DNS-resolved before they are accepted. Static bootstrap hosts are
not filtered by `allowed_hosts`.

`status.json` is a runtime snapshot of the currently running update process. It
is enabled by default and is written atomically so browser polling should always
see valid JSON. The file stores only the current state, not an event history.
Enum-backed fields such as `state`, `current.phase`, and `current.action` are
objects with stable machine keys and localized labels:

```json
{
	"schema_version": 1,
	"state": { "key": "running", "label": "Running" },
	"current": {
		"phase": { "key": "processing_version", "label": "Processing version" },
		"action": { "key": "checking_mirror_versions", "label": "Checking mirror versions" },
		"version": "ep9",
		"version_name": "ESET NOD32 Endpoint 9",
		"channel": "production",
		"variant": "production:file"
	},
	"versions": {
		"items": {
			"ep9": {
				"database": {
					"version": {
						"local": 33200,
						"remote": 33203,
						"result": null
					}
				}
			}
		}
	}
}
```

The bundled `tools/index.html` polls `./status.json`. Polling frequency is
configurable in the page UI and is stored in browser `localStorage` under
`nod32Mirror.statusPollMs` with a default of 2000 ms.

SQLite stores blob metadata, published paths, and version/channel references in
normalized tables. This allows garbage collection to delete individual rows and
scan orphaned blobs without rebuilding or loading the complete index in memory.
