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
`state.database.file` is resolved from `state.root`; report file names are
resolved from `web.root`.

`link_method` can be `hardlink`, `softlink`, or `copy`. Use `copy` when
`storage` and `www` are separate Docker mounts. `hardlink` requires both paths
to be on the same filesystem. `state.database.file` configures the SQLite
metadata database. With the example configuration it resolves to
`/data/data/content-index.sqlite`. Absolute file paths are also accepted. The
worker creates the SQLite database automatically. `runtime.php.memory_limit`
is applied to the PHP process at startup with `ini_set()`.

`storage.gc.excludes` contains paths, prefixes, or glob patterns relative to
`web.root` that GC must leave untouched, for example `custom/`, `robots.txt`,
`*.json`, or `.well-known/**`. In globs, `*` matches within one path segment and
`**` matches across directories. Leading `/` or `./` is ignored, so
`/robots.txt`, `./robots.txt`, and `robots.txt` are equivalent.

Report files are configured with `web.reports.json.file`,
`web.reports.html.file`, and `web.reports.status.file`. They are relative to
`web.root`; parent directories are created automatically.

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
