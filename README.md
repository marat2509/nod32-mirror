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
under `script.storage.dir` and publishes compatible paths into `webDir`.

Default config:

```yaml
script:
  dir: /data
  web_dir: www
  storage:
    dir: storage
    hash: sha256
    link_method: hardlink
    gc:
      enabled: false
      excludes: []
  generate:
    json:
      enabled: true
      path: index.json
    html:
      enabled: true
      path: index.html
    status:
      enabled: true
      path: status.json
```

The Docker example mounts `./docker-data` into the worker as `/data`.
`script.dir` is the base directory for relative runtime paths such as
`web_dir`, `data.dir`, `log.file.dir`, and `storage.dir`. Absolute paths in
those fields are still accepted and override `script.dir`.

`link_method` can be `hardlink`, `softlink`, or `copy`. Use `copy` when
`storage` and `www` are separate Docker mounts. `hardlink` requires both paths
to be on the same filesystem. The canonical metadata file is
`<data.dir>/content-index.json`, for example `/data/data/content-index.json`.

`script.storage.gc.excludes` contains paths, prefixes, or glob patterns relative
to `web_dir` that GC must leave untouched, for example `custom/`, `robots.txt`,
`*.json`, or `.well-known/**`. In globs, `*` matches within one path segment and
`**` matches across directories. Leading `/` or `./` is ignored, so
`/robots.txt`, `./robots.txt`, and `robots.txt` are equivalent.

Report paths are configured with `script.generate.json.path`,
`script.generate.html.path`, and `script.generate.status.path`. They are
relative to `web_dir`; parent directories are created automatically.

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

Schema (published paths are relative to `webDir`):

```json
{
	"hash_algorithm": "sha256",
	"updated_at": "2026-05-20T12:34:56+00:00",
	"hashes": {
		"abcdef": {
			"hash": "abcdef",
			"size": 104857600,
			"blob_path": "/data/storage/blobs/sha256/ab/cd/abcdef",
			"published_paths": ["eset_upd/ep9/production/base/defs.nup"],
			"version_ids": { "ep9": ["production"] },
			"created_at": "2026-05-20T12:00:00+00:00",
			"updated_at": "2026-05-20T12:34:56+00:00"
		}
	},
	"published": {}
}
```
