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

Report paths are configured with `script.generate.json.path` and
`script.generate.html.path`. They are relative to `web_dir`; parent directories
are created automatically.

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
