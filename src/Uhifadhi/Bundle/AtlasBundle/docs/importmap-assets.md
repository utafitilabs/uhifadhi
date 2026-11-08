# Shipping importmap assets from a bundle

Written down because it is the part with the sharp edges.

## Contents

- [What lands in a host](#what-lands-in-a-host)
- [The plate controller](#the-plate-controller)
- [How it works](#how-it-works)

## What lands in a host

**The shared modules in `importmap.php`** — **Flex writes these automatically**, and no
longer by way of the recipe. The core declares them itself, in `assets/package.json`, and
Symfony Flex runs `importmap:require` once per entry on install. What lands in a host is:

```php
'uhifadhi/basemaps'   => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/basemaps.js'],
'uhifadhi/boundary'   => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/boundary.js'],
'uhifadhi/map-chrome' => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/AtlasBundle/assets/chrome.js'],
'uhifadhi/widgets'    => ['path' => './vendor/uhifadhi/uhifadhi/src/Uhifadhi/Bundle/ShellBundle/assets/widgets.js'],
```

**The chart packages** — Chart.js's date adapter and the five extension packages the chart kinds
need, with the libraries under them — are declared in the same block by name and pinned version,
as `symfony/ux-chartjs` declares `chart.js`
([create a UX bundle](https://symfony.com/doc/current/frontend/create_ux_bundle.html): "When an
application installs your package, Symfony Flex runs the `importmap:require` command to add each
`importmap` entry"). Flex downloads each into `assets/vendor/` and writes:

```php
'chartjs-adapter-date-fns'       => ['version' => '3.0.0'],
'date-fns'                       => ['version' => '2.30.0'],
'chartjs-chart-matrix'           => ['version' => '3.1.0'],
'chartjs-chart-treemap'          => ['version' => '4.2.2'],
'chartjs-chart-sankey'           => ['version' => '0.19.1'],
'@sgratzl/chartjs-chart-boxplot' => ['version' => '4.4.5'],
'@sgratzl/boxplots'              => ['version' => '2.0.0'],
'chartjs-chart-funnel'           => ['version' => '4.2.5'],
'chroma-js'                      => ['version' => '3.2.0'],
'chart.js/helpers'               => ['version' => '4.5.1'],
```

None is preloaded: the chart plate imports each with a dynamic `import()`, which AssetMapper
leaves out of the page's preloads, and only where a chart on the page needs it.

**WHICH `assets/package.json`, THOUGH.** Flex builds ONE path per installed composer package —
`<vendor-dir>/<the package's name>/assets/package.json` — and looks nowhere else
(`PackageJsonSynchronizer::resolvePackageJson`). The bundles here are names the core `replace`s;
none of them is installed and none of their manifests is ever opened. So a bundle declares its own
modules for the day it becomes a package of its own, and the **root** `assets/package.json`
declares the same list for today, exactly as it aggregates every bundle's Stimulus controllers.
`tests/Core/ImportmapPackageTest` is what keeps the two one list — a name published only by a
bundle reaches no installation, silently.

The paths are the vendor-relative form because that is what `importmap:require` writes — it
resolves whatever path it is given back to an asset and then stores the shortest form it can. The
equivalent logical path `@uhifadhi/atlas-bundle/basemaps.js` resolves to the same file, and either is
correct in a hand-written `importmap.php`.

Two conditions and no more: the host must **have** an `importmap.php` (i.e. run AssetMapper — a
host that installed this bundle for the Leaflet build alone has nothing to write into, and Flex
writes nothing), and `symfony/flex` must be allowed to run its plugin. Neither is special to this
package: it is how `symfony/stimulus-bundle` gets its loader into your importmap too.

## The plate controller

A Stimulus controller is not an importmap entry. It is declared in the same `assets/package.json`,
under `symfony.controllers`, and an installation enables it in `assets/controllers.json`:

```json
"@uhifadhi/uhifadhi": {
    "map-plate": { "enabled": true, "fetch": "eager" }
}
```

The declaration carries an explicit `name`, so the identifier a template writes is
`uhifadhi--atlas-bundle--map-plate` whichever package key an installation enables it under — the
core ships as one composer package and its `assets/package.json` aggregates every bundle's
controllers, so without the explicit name the identifier would say `uhifadhi--uhifadhi--`.

Templates never write that identifier: `render_map()` does.

`symfony/ux-leaflet-map` contributes its own entries the same way, and one of them is the single
`leaflet` this package relies on:

```php
'leaflet' => ['version' => '1.9.4'],
'leaflet/dist/leaflet.min.css' => ['version' => '1.9.4', 'type' => 'css'],
'@symfony/ux-leaflet-map' => ['path' => './vendor/symfony/ux-leaflet-map/assets/dist/map_controller.js'],
'@symfony/ux-map' => ['path' => './vendor/symfony/ux-map/assets/dist/abstract_map_controller.js'],
```

## How it works

- A bundle registers an asset directory by **prepending `framework.asset_mapper.paths`** with a
  namespace, the way `symfony/ux-turbo` does. Every file under it then has a logical path
  beginning with that namespace.
- A bundle's `public/` directory is registered automatically under
  `bundles/<lowercased bundle class name without "Bundle">` — no configuration, no `assets:install`.
  Relative `url()`s inside a CSS file there are rewritten, so Leaflet's marker PNGs come along.
- **A bundle cannot add importmap entries — but a *package* can.** `importmap.php` is read as one
  file and AssetMapper exposes no extension point, which is true and was never the whole story: the
  thing that writes a host's `importmap.php` on install is not AssetMapper, it is Flex. Declare the
  entries in `assets/package.json` under `symfony.importmap` and Flex runs `importmap:require` for
  each one (`PackageJsonSynchronizer::resolveImportMapPackages`, `::updateImportMap`):

  ```json
  "symfony": {
      "importmap": {
          "uhifadhi/basemaps": "path:%PACKAGE%/basemaps.js"
      }
  }
  ```

  `%PACKAGE%` becomes the directory holding `assets/package.json`, so the entry names a real file
  whatever the host's vendor layout is. It is the form `symfony/stimulus-bundle` ships its loader
  with.
- **The keyword is the whole switch.** Flex opens a package's `assets/package.json` only if the
  composer package declares `symfony-ux` in its `keywords`
  (`PackageJsonSynchronizer::resolvePackageJson`). Without it everything installs and nothing is
  written — no error, just a blank map on every page that draws one. Hence a test that asserts the
  keyword rather than trusting it to survive the next edit of `composer.json`.
- A *recipe* cannot do this job: recipes copy files and patch YAML, and `importmap.php` is PHP.
  That is why the entries live in the package and the recipe carries only `config/packages/atlas.yaml`.
- The guard on the prepend matters: `interface_exists(AssetMapperInterface::class)` as well as
  `hasExtension('framework')`, because AssetMapper is optional and a host may install this bundle
  for the Leaflet build alone.
- Import names are **bare specifiers** (`uhifadhi/basemaps`), not paths, precisely so this bundle
  could move underneath them — which is exactly what happened when they left the host application.
