<?php

namespace Statikbe\LaravelChainedTranslator;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\SplFileInfo;
use Brick\VarExporter\VarExporter;
use File;

class ChainedTranslationManager
{
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * The default path for the loader.
     *
     * @var string
     */
    protected $path;

    /**
     * @var ChainLoader $translationLoader
     */
    private $translationLoader;

    /**
     * Create a new file loader instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $files
     * @param ChainLoader $translationLoader
     * @param string $path
     */
    public function __construct(Filesystem $files, ChainLoader $translationLoader, string $path)
    {
        $this->path = $path;
        $this->files = $files;
        $this->translationLoader = $translationLoader;
    }

    /**
     * Saves a translation
     *
     * @param string $locale
     * @param string $group
     * @param string $key
     * @param string $translation
     * @return void
     */
    public function save(string $locale, string $group, string $key, string $translation): void
    {
        if (! $this->localeFolderExists($locale)) {
            $this->createLocaleFolder($locale);
        }

        $translations = $this->getGroupTranslations($locale, $group);

        $translations->put($key, $translation);

        $this->saveGroupTranslations($locale, $group, $translations);
    }

    /**
     * Returns a list of translation groups. A translation group is the file name of the PHP files in the resources/lang
     * directory.
     * @return array
     */
    public function getTranslationGroups(): array
    {
        $groups = [];
        $langDirPath = resource_path('lang');
        $filesAndDirs = $this->files->allFiles($langDirPath);
        foreach ($filesAndDirs as $file) {
            /* @var SplFileInfo $file */
            if (!$file->isDir()) {
                $group = null;
                $vendorPath = strstr($file->getRelativePath(), 'vendor');
                $prefix = '';
                if ($vendorPath) {
                    $vendorPathParts = explode('vendor'.DIRECTORY_SEPARATOR, $vendorPath);
                    if(count($vendorPathParts) > 1){
                        $vendorPath = $vendorPathParts[1];
                    }
                    //remove locale from vendor path for php files, json files have the locale in the file name, eg. en.json
                    if (strtolower($file->getExtension()) === 'php') {
                        $vendorPath = substr($vendorPath, 0, strrpos($vendorPath, DIRECTORY_SEPARATOR));
                    }
                    $prefix = $vendorPath.'/';
                }
                if (strtolower($file->getExtension()) === 'php') {
                    $group = $prefix.$file->getFilenameWithoutExtension();
                } else {
                    if (strtolower($file->getExtension()) === 'json') {
                        if ($prefix) {
                            $group = $vendorPath;
                        } else {
                            $group = 'single';
                        }
                    }
                }
                if ($group) {
                    $groups[$group] = $group;
                }
            }
        }

        return array_values($groups);
    }

    public function getTranslationsForGroup(string $locale, string $group): array
    {
        if (Str::contains($group, '/')){
            [$namespace, $group] = explode('/', $group);
        }

        if ($group === 'single') {
            return $this->compressHierarchicalTranslationsToDotNotation($this->translationLoader->load($locale, '*', '*'));
        } else {
            return $this->compressHierarchicalTranslationsToDotNotation($this->translationLoader->load($locale, $group, $namespace ?? null));
        }
    }

    public function mergeChainedTranslationsIntoDefaultTranslations(string $locale): void {
        $defaultLangPath = App::get('path.lang');
        if (! $this->localeFolderExists($locale)) {
            $this->createLocaleFolder($locale);
        }

        $groups = $this->getTranslationGroups();

        foreach($groups as $group){
            $translations = $this->getGroupTranslations($locale, $group);
            if ($translations->isNotEmpty()){
                $this->saveGroupTranslations($locale, $group, $translations, $defaultLangPath);
            }
        }
    }

    private function compressHierarchicalTranslationsToDotNotation(array $translations): array
    {
        $iteratorIterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($translations));
        $result = array();
        foreach ($iteratorIterator as $leafValue) {
            $keys = array();
            foreach (range(0, $iteratorIterator->getDepth()) as $depth) {
                $keys[] = $iteratorIterator->getSubIterator($depth)->key();
            }
            $result[ join('.', $keys) ] = $leafValue;
        }
        return $result;
    }

    private function localeFolderExists(string $locale): bool
    {
        return $this->files->exists($this->path.DIRECTORY_SEPARATOR.$locale);
    }

    private function createLocaleFolder(string $locale): bool
    {
        return $this->files->makeDirectory($this->path.DIRECTORY_SEPARATOR.$locale, 0755, true);
    }

    private function getGroupTranslations(string $locale, string $group): Collection
    {
        $groupPath = $this->getGroupPath($locale, $group);

        if($this->files->exists($groupPath)){
            return collect($this->files->getRequire($groupPath));
        }

        return collect([]);
    }

    private function saveGroupTranslations(string $locale, string $group, Collection $translations, string $languagePath=null): void
    {
        // here we check if it's a namespaced translation which need saving to a
        // different path
        $translations = $translations->toArray();
        $translations = array_undot($translations);
        ksort($translations);

        $groupPath = $this->getGroupPath($locale, $group, $languagePath);

        // Merge the changed translations with the defaults so we always keep a updated version of everything
        // Fixes a bug where some translations arent being loaded
        $baseTranslations = collect($this->loadBaseTranslations()[$locale][$group]);
        $translations = $baseTranslations->replaceRecursive($translations)->toArray();
        $this->files->put($groupPath, "<?php\n\nreturn ".VarExporter::export($translations).';'.\PHP_EOL);
    }

    private function getGroupPath(string $locale, string $group, string $languagePath=null): string
    {
        $basePath = $this->getGroupBasePath($locale, $group, $languagePath);

        if (Str::contains($group, '/')){
            [$namespace, $group] = explode('/', $group);
        }

        return $basePath.DIRECTORY_SEPARATOR.$group.'.php';
    }

    private function getGroupBasePath(string $locale, string $group, string $languagePath=null): string
    {
        $languagePath = ($languagePath ?? $this->path);
        if (Str::contains($group, '/')){
            [$namespace, $group] = explode('/', $group);

            $groupBasePath = $languagePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.$namespace.DIRECTORY_SEPARATOR.$locale;
            $this->createDirectory($groupBasePath);
            return $groupBasePath;
        }

        $groupBasePath = $languagePath.DIRECTORY_SEPARATOR.$locale;

        //create directory if not exists:
        $this->createDirectory($groupBasePath);

        return $groupBasePath;
    }

    private function createDirectory(string $path): void
    {
        if(!$this->files->exists($path)){
            $this->files->makeDirectory($path,  0755, true);
        }
    }

    private function loadBaseTranslations(){
        $translations = collect(File::directories(resource_path('lang')))->mapWithKeys(function ($dir) {
            return [
                basename($dir) => collect($this->getBaseTranslationsFiles($dir))->flatMap(function ($file) {
                    $name = str_replace(['.php', '.js'], '', $file->getBaseName());
                    if (in_array($name, [], true)) {
                        return [];
                    }
                    $keys = include $file->getPathname() ?? [];
                    return [
                        $file->getBasename('.php') => empty($keys) ? [] : $keys,
                    ];
                }),
            ];
        });

        $packageTranslations = collect([]);

        return $translations
            ->keys()
            ->merge($packageTranslations->keys())
            ->unique()
            ->values()
            ->mapWithKeys(static function ($locale) use ($translations, $packageTranslations) {
                return [
                    $locale => $translations->has($locale)
                        ? $translations->get($locale)->merge($packageTranslations->get($locale))
                        : $packageTranslations->get($locale)->merge($translations->get(config('app.fallback_locale'))),
                ];
            });
    }

    protected function getBaseTranslationsFiles(string $dir)
    {
        if (is_dir($dir)) {
            return File::files($dir);
        }
        return [];
    }
}
