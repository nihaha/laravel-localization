<?php namespace Mcamara\LaravelLocalization;

use Illuminate\Config\Repository;
use Illuminate\View\Factory;
use Illuminate\Translation\Translator;
use Illuminate\Routing\Router;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\URL;
use Session;
use Cookie;


class LaravelLocalization {

    /**
     * Config repository.
     *
     * @var \Illuminate\Config\Repository
     */
    protected $configRepository;

    /**
     * Illuminate view Factory.
     *
     * @var \Illuminate\View\Factory
     */
    protected $view;

    /**
     * Illuminate translator class.
     *
     * @var \Illuminate\Translation\Translator
     */
    protected $translator;

    /**
     * Illuminate router class.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * Illuminate request class.
     *
     * @var \Illuminate\Routing\Request
     */
    protected $request;

    /**
     * Illuminate request class.
     *
     * @var Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * Illuminate request class.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Default locale
     *
     * @var string
     */
    protected $defaultLocale;

    /**
     * Supported Locales
     *
     * @var array
     */
    protected $supportedLocales;

    /**
     * Current locale
     *
     * @var string
     */
    protected $currentLocale = false;

    /**
     * An array that contains all routes that should be translated
     *
     * @var array
     */
    protected $translatedRoutes = array();

    /**
     * Name of the translation key of the current route, it is used for url translations
     *
     * @var string
     */
    protected $routeName;


    /**
     * Name of the locale variable for the session and cookie storages
     *
     * @var string
     */
    protected $cookieSessionName = 'locale';

    /**
     * Creates new instance.
     *
     * @param Repository $configRepository
     * @param Factory $view
     * @param Translator $translator
     * @param Router $router
     * @param Application $app
     *
     * @throws UnsupportedLocaleException
     *
     */
    public function __construct( Repository $configRepository, Factory $view, Translator $translator, Router $router, Application $app )
    {
        $this->configRepository = $configRepository;
        $this->view = $view;
        $this->translator = $translator;
        $this->router = $router;

        $this->app = $app;
        $this->request = $this->app[ 'request' ];

        // set default locale
        $this->defaultLocale = $this->configRepository->get('app.locale');
        $supportedLocales = $this->getSupportedLocales();

        if ( empty( $supportedLocales[ $this->defaultLocale ] ) )
        {
            throw new UnsupportedLocaleException("Laravel's default locale is not in the supportedLocales array.");
        }
    }

    /**
     * Set and return current locale
     *
     * @param  string $locale Locale to set the App to (optional)
     *
     * @return string                    Returns locale (if route has any) or null (if route does not have a locale)
     */
    public function setLocale( $locale = null )
    {
        if ( empty( $locale ) || !is_string($locale) )
        {
            // If the locale has not been passed through the function
            // it tries to get it from the first segment of the url
            $locale = $this->request->segment(1);
        }

        if ( !empty( $this->supportedLocales[ $locale ] ) )
        {
            $this->currentLocale = $locale;
        } else
        {
            // if the first segment/locale passed is not valid
            // the system would ask which locale have to take
            // it could be taken by session, browser or app default
            // depending on your configuration

            $locale = null;

            // if we reached this point and hideDefaultLocaleInURL is true
            // we have to assume we are routing to a defaultLocale route.
            if ( $this->hideDefaultLocaleInURL() )
            {
                $this->currentLocale = $this->defaultLocale;
            }
            // but if hideDefaultLocaleInURL is false, we have
            // to retrieve it from the session/cookie/browser...
            else
            {
                $this->currentLocale = $this->getCurrentLocale();
            }
        }

        $this->app->setLocale($this->currentLocale);

        $this->storeSession($this->currentLocale);

        $this->storeCookie($this->currentLocale);

        return $locale;
    }

    /**
     * Set and return supported locales
     *
     * @param  array $locales Locales that the App supports
     */
    public function setSupportedLocales( $locales )
    {
        $this->supportedLocales = $locales;
    }

    /**
     * Returns an URL adapted to $locale or current locale
     *
     * @param  string $url URL to adapt. If not passed, the current url would be taken.
     * @param  string|boolean $locale Locale to adapt, false to remove locale
     *
     * @throws UnsupportedLocaleException
     *
     * @return string                       URL translated
     */
    public function localizeURL( $url = null, $locale = null )
    {
        return $this->getLocalizedURL($locale, $url);
    }


    /**
     * Returns an URL adapted to $locale
     *
     * @throws SupportedLocalesNotDefined
     * @throws UnsupportedLocaleException
     *
     * @param  string|boolean $locale Locale to adapt, false to remove locale
     * @param  string|false $url URL to adapt in the current language. If not passed, the current url would be taken.
     * @param  array $attributes Attributes to add to the route, if empty, the system would try to extract them from the url.
     *
     *
     * @return string|false                URL translated, False if url does not exist
     */
    public function getLocalizedURL( $locale = null, $url = null, $attributes = array() )
    {
        if ( is_null($locale) )
        {
            $locale = $this->getCurrentLocale();
        }

        $this->checkLocaleInSupportedLocales($locale);

        if ( empty( $attributes ) )
        {
            $attributes = $this->extractAttributes($url);
        }

        if ( $locale && $translatedRoute = $this->findTranslatedRouteByUrl($url, $attributes, $this->currentLocale) )
        {
            return $this->getURLFromRouteNameTranslated($locale, $translatedRoute, $attributes);
        }

        if ( empty( $url ) )
        {
            if ( !empty( $this->routeName ) )
            {
                return $this->getURLFromRouteNameTranslated($locale, $this->routeName, $attributes);
            }

            $url = $this->request->fullUrl();

        }

        $base_path = $this->request->getBaseUrl();
        $parsed_url = parse_url($url);
        $url_locale = $this->getDefaultLocale();

        if ( !$parsed_url || empty( $parsed_url[ 'path' ] ) )
        {
            $path = $parsed_url[ 'path' ] = "";
        } else
        {
            $parsed_url[ 'path' ] = str_replace($base_path, '', '/' . ltrim($parsed_url[ 'path' ], '/'));
            $path = $parsed_url[ 'path' ];
            foreach ( $this->getSupportedLocales() as $localeCode => $lang )
            {
                $parsed_url[ 'path' ] = preg_replace('%^/?' . $localeCode . '/%', '$1', $parsed_url[ 'path' ]);
                if ( $parsed_url[ 'path' ] !== $path )
                {
                    $url_locale = $localeCode;
                    break;
                }

                $parsed_url[ 'path' ] = preg_replace('%^/?' . $localeCode . '$%', '$1', $parsed_url[ 'path' ]);
                if ( $parsed_url[ 'path' ] !== $path )
                {
                    $url_locale = $localeCode;
                    break;
                }
            }
        }

        $parsed_url[ 'path' ] = ltrim($parsed_url[ 'path' ], '/');

        if ( $translatedRoute = $this->findTranslatedRouteByPath($parsed_url[ 'path' ], $url_locale) )
        {
            return $this->getURLFromRouteNameTranslated($locale, $translatedRoute, $attributes);
        }

        if ( !empty( $locale ) && ( $locale != $this->defaultLocale || !$this->hideDefaultLocaleInURL() ) )
        {
            $parsed_url[ 'path' ] = $locale . '/' . ltrim($parsed_url[ 'path' ], '/');
        }
        $parsed_url[ 'path' ] = ltrim(ltrim($base_path, '/') . '/' . $parsed_url[ 'path' ], '/');

        //Make sure that the pass path is returned with a leading slash only if it come in with one.
        if ( starts_with($path, '/') === true )
        {
            $parsed_url[ 'path' ] = '/' . $parsed_url[ 'path' ];
        }
        $parsed_url[ 'path' ] = rtrim($parsed_url[ 'path' ], '/');

        $url = $this->unparseUrl($parsed_url);

        if ( $this->checkUrl($url) )
        {
            return $url;
        }

        return $this->createUrlFromUri($url);
    }


    /**
     * Returns an URL adapted to the route name and the locale given
     *
     * @throws SupportedLocalesNotDefined
     * @throws UnsupportedLocaleException
     *
     * @param  string|boolean $locale Locale to adapt
     * @param  string $transKeyName Translation key name of the url to adapt
     * @param  array $attributes Attributes for the route (only needed if transKeyName needs them)
     *
     * @return string|false    URL translated
     */
    public function getURLFromRouteNameTranslated( $locale, $transKeyName, $attributes = array() )
    {
        $this->checkLocaleInSupportedLocales($locale);

        $route = "";

        if ( !( $locale === $this->defaultLocale && $this->hideDefaultLocaleInURL() ) )
        {
            $route = '/' . $locale;
        }

        if ( is_string($locale) && $this->translator->has($transKeyName, $locale) )
        {
            $translation = $this->translator->trans($transKeyName, [ ], "", $locale);
            $route .= "/" . $translation;

            $route = $this->substituteAttributesInRoute($attributes, $route);

        }

        if ( empty( $route ) )
        {
            // This locale does not have any key for this route name
            return false;
        }

        return rtrim($this->createUrlFromUri($route));


    }

    /**
     * It returns an URL without locale (if it has it)
     * Convenience function wrapping getLocalizedURL(false)
     *
     * @param  string|false $url URL to clean, if false, current url would be taken
     *
     * @return string           URL with no locale in path
     */
    public function getNonLocalizedURL( $url = null )
    {
        return $this->getLocalizedURL(false, $url);
    }

    /**
     * Returns default locale
     *
     * @return string
     */
    public function getDefaultLocale()
    {
        return $this->defaultLocale;
    }

    /**
     * Return an array of all supported Locales
     *
     * @throws SupportedLocalesNotDefined
     * @return array
     */
    public function getSupportedLocales()
    {
        if ( !empty( $this->supportedLocales ) )
        {
            return $this->supportedLocales;
        }

        $locales = $this->configRepository->get('laravel-localization::supportedLocales');

        if ( empty( $locales ) || !is_array($locales) )
        {
            throw new SupportedLocalesNotDefined();
        }

        $this->supportedLocales = $locales;

        return $locales;
    }

    /**
     * Returns current locale name
     *
     * @return string current locale name
     */
    public function getCurrentLocaleName()
    {
        return $this->supportedLocales[ $this->getCurrentLocale() ][ 'name' ];
    }

    /**
     * Returns current locale direction
     *
     * @return string current locale direction
     */
    public function getCurrentLocaleDirection()
    {
        return $this->supportedLocales[ $this->getCurrentLocale() ][ 'dir' ];
    }

    /**
     * Returns current locale script
     *
     * @return string current locale script
     */
    public function getCurrentLocaleScript()
    {
        return $this->supportedLocales[ $this->getCurrentLocale() ][ 'script' ];
    }

    /**
     * Returns current language's native reading
     *
     * @return string current language's native reading
     */
    public function getCurrentLocaleNativeReading()
    {
        return $this->supportedLocales[ $this->getCurrentLocale() ][ 'native' ];
    }

    /**
     * Returns current language
     *
     * @return string current language
     */
    public function getCurrentLocale()
    {
        if ( $this->currentLocale )
        {
            return $this->currentLocale;
        }

        // get session language...
        if ( $locale = $this->getSessionLocale() )
        {
            return $locale;
        }

        // or get cookie language...
        if ( $locale = $this->getCookieLocale() )
        {
            return $locale;
        }

        // or get browser language...
        if ( $this->useAcceptLanguageHeader() )
        {
            return $this->negotiateLanguage();
        }

        // or get application default language
        return $this->configRepository->get('app.locale');
    }

    /**
     * Returns supported languages language key
     *
     * @return array    keys of supported languages
     */
    public function getSupportedLanguagesKeys()
    {
        return array_keys($this->supportedLocales);
    }


    /**
     * Returns the locale stored on the session (if available)
     *
     * @return string|false Locale stored in session - False if it doesn't exist
     */
    protected function getSessionLocale()
    {
        if ( $this->useSessionLocale() && Session::has($this->cookieSessionName) )
        {
            return Session::get($this->cookieSessionName);
        }

        return false;
    }

    /**
     * Store the locale on a session variable
     * @param $locale string Locale to save on session variable
     */
    protected function storeSession( $locale )
    {
        if ( $this->useSessionLocale() )
        {
            Session::put($this->cookieSessionName, $locale);
        }
    }

    /**
     * Returns the locale stored on the cookies (if available)
     *
     * @return string|false Locale stored in cookies - False if it doesn't exist
     */
    protected function getCookieLocale()
    {
        if ( $this->useCookieLocale() && Cookie::has($this->cookieSessionName) )
        {
            return Cookie::get($this->cookieSessionName);
        }

        return false;
    }

    /**
     * Store the locale on a cookie variable
     * @param $locale String Locale to store on Cookie variable
     */
    protected function storeCookie( $locale )
    {
        Cookie::forget($this->cookieSessionName);
        if ( $this->useCookieLocale() )
        {
            Cookie::queue(Cookie::forever($this->cookieSessionName, $locale));
        }
    }

    /**
     * Check if Locale exists on the supported locales array
     *
     * @param string|boolean $locale string|bool Locale to be checked
     * @throws SupportedLocalesNotDefined
     * @throws UnsupportedLocaleException
     */
    public function checkLocaleInSupportedLocales( $locale )
    {
        $locales = $this->getSupportedLocales();
        if ( $locale !== false && empty( $locales[ $locale ] ) )
        {
            throw new UnsupportedLocaleException('Locale \'' . $locale . '\' is not in the list of supported locales.');
        }
    }

    /**
     * Change route attributes for the ones in the $attributes array
     *
     * @param $attributes array Array of attributes
     * @param string $route string route to substitute
     * @return string route with attributes changed
     */
    protected function substituteAttributesInRoute( $attributes, $route )
    {
        foreach ( $attributes as $key => $value )
        {
            $route = str_replace("{" . $key . "}", $value, $route);
            $route = str_replace("{" . $key . "?}", $value, $route);
        }

        // delete empty optional arguments that are not in the $attributes array
        $route = preg_replace('/\/{[^)]+\?}/', '', $route);

        return $route;
    }

    /**
     * Returns translated routes
     *
     * @return array translated routes
     */
    protected function getTranslatedRoutes()
    {
        return $this->translatedRoutes;
    }

    /**
     * Set current route name
     * @param string $routeName current route name
     */
    public function setRouteName( $routeName )
    {
        $this->routeName = $routeName;
    }

    /**
     * Translate routes and save them to the translated routes array (used in the localize route filter)
     *
     * @param  string $routeName Key of the translated string
     *
     * @return string                Translated string
     */
    public function transRoute( $routeName )
    {
        if ( !in_array($routeName, $this->translatedRoutes) )
        {
            $this->translatedRoutes[ ] = $routeName;
        }

        return $this->translator->trans($routeName);
    }

    /**
     * Returns the translation key for a given path
     *
     * @param  string $path Path to get the key translated
     *
     * @return string|false            Key for translation, false if not exist
     */
    public function getRouteNameFromAPath( $path )
    {
        $path = str_replace(url(), "", $path);
        if ( $path[ 0 ] !== '/' )
        {
            $path = '/' . $path;
        }
        $path = str_replace('/' . $this->currentLocale . '/', '', $path);
        $path = trim($path, "/");

        foreach ( $this->translatedRoutes as $route )
        {
            if ( $this->translator->trans($route) == $path )
            {
                return $route;
            }
        }

        return false;
    }

    /**
     * Returns the translated route for the path and the url given
     *
     * @param  string $path Path to check if it is a translated route
     * @param  string $url_locale Language to check if the path exists
     *
     * @return string|false            Key for translation, false if not exist
     */
    protected function findTranslatedRouteByPath( $path, $url_locale )
    {
        // check if this url is a translated url
        foreach ( $this->translatedRoutes as $translatedRoute )
        {
            if ( $this->translator->trans($translatedRoute, [ ], "", $url_locale) == $path )
            {
                return $translatedRoute;
            }
        }

        return false;
    }

    /**
     * Returns the translated route for an url and the attributes given and a locale
     *
     * @throws SupportedLocalesNotDefined
     * @throws UnsupportedLocaleException
     *
     * @param  string|false|null $url Url to check if it is a translated route
     * @param  array $attributes Attributes to check if the url exists in the translated routes array
     * @param  string $locale Language to check if the url exists
     *
     * @return string|false                Key for translation, false if not exist
     */
    protected function findTranslatedRouteByUrl( $url, $attributes, $locale )
    {
        if ( empty( $url ) )
        {
            return false;
        }

        // check if this url is a translated url
        foreach ( $this->translatedRoutes as $translatedRoute )
        {
            $routeName = $this->getURLFromRouteNameTranslated($locale, $translatedRoute, $attributes);

            if ( $this->getNonLocalizedURL($routeName) == $this->getNonLocalizedURL($url) )
            {
                return $translatedRoute;
            }

        }

        return false;
    }

    /**
     * Returns true if the string given is a valid url
     *
     * @param  string $url String to check if it is a valid url
     *
     * @return boolean        Is the string given a valid url?
     */
    protected function checkUrl( $url )
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }


    /**
     * Returns the config repository for this instance
     *
     * @return Repository    Configuration repository
     *
     */
    public function getConfigRepository()
    {
        return $this->configRepository;
    }

    /**
     * Returns the translation key for a given path
     *
     * @return boolean       Returns value of useSessionLocale in config.
     */
    protected function useSessionLocale()
    {
        return $this->configRepository->get('laravel-localization::useSessionLocale');
    }

    /**
     * Returns the translation key for a given path
     *
     * @return boolean       Returns value of useCookieLocale in config.
     */
    protected function useCookieLocale()
    {
        return $this->configRepository->get('laravel-localization::useCookieLocale');
    }

    /**
     * Returns the translation key for a given path
     *
     * @return boolean       Returns value of useAcceptLanguageHeader in config.
     */
    protected function useAcceptLanguageHeader()
    {
        return $this->configRepository->get('laravel-localization::useAcceptLanguageHeader');
    }

    /**
     * Returns the translation key for a given path
     *
     * @return boolean       Returns value of hideDefaultLocaleInURL in config.
     */
    public function hideDefaultLocaleInURL()
    {
        return $this->configRepository->get('laravel-localization::hideDefaultLocaleInURL');
    }

    /**
     * Create an url from the uri
     * @param    string $uri Uri
     *
     * @return  string  Url for the given uri
     */
    public function createUrlFromUri( $uri )
    {
        if ( empty( $this->baseUrl ) )
        {
            return URL::to($uri);
        }

        return $this->baseUrl . ltrim($uri, "/");
    }

    /**
     * Sets the base url for the site
     * @param string $url Base url for the site
     *
     */
    public function setBaseUrl( $url )
    {
        if ( substr($url, -1) != "/" )
            $url .= "/";

        $this->baseUrl = $url;
    }

    /**
     * Extract attributes for current url
     *
     * @param  string|null|false $url to extract attributes, if not present, the system will look for attributes in the current call
     *
     * @return array    Array with attributes
     *
     */
    protected function extractAttributes( $url = false )
    {
        if ( !empty( $url ) )
        {
            $attributes = [ ];
            $parse = parse_url($url);
            $parse = explode("/", $parse[ 'path' ]);
            $url = [ ];
            foreach ( $parse as $segment )
            {
                if ( !empty( $segment ) )
                    $url[ ] = $segment;
            }

            foreach ( $this->router->getRoutes() as $route )
            {
                $path = $route->getUri();
                if ( !preg_match("/{[\w]+}/", $path) )
                {
                    continue;
                }

                $path = explode("/", $path);
                $i = 0;

                $match = true;
                foreach ( $path as $j => $segment )
                {
                    if ( isset( $url[ $i ] ) )
                    {
                        if ( $segment === $url[ $i ] )
                        {
                            $i++;
                            continue;
                        }
                        if ( preg_match("/{[\w]+}/", $segment) )
                        {
                            // must-have parameters
                            $attribute_name = preg_replace([ "/}/", "/{/", "/\?/" ], "", $segment);
                            $attributes[ $attribute_name ] = $url[ $i ];
                            $i++;
                            continue;
                        }
                        if ( preg_match("/{[\w]+\?}/", $segment) )
                        {
                            // optional parameters
                            if ( !isset( $path[ $j + 1 ] ) || $path[ $j + 1 ] !== $url[ $i ] )
                            {
                                // optional parameter taken
                                $attribute_name = preg_replace([ "/}/", "/{/", "/\?/" ], "", $segment);
                                $attributes[ $attribute_name ] = $url[ $i ];
                                $i++;
                                continue;
                            }

                        }
                    } else if ( !preg_match("/{[\w]+\?}/", $segment) )
                    {
                        // no optional parameters but no more $url given
                        // this route does not match the url
                        $match = false;
                        break;
                    }
                }

                if ( isset( $url[ $i + 1 ] ) )
                {
                    $match = false;
                }

                if ( $match )
                {
                    return $attributes;
                }
            }

        } else
        {
            if ( !$this->router->current() )
            {
                return [ ];
            }

            $attributes = $this->router->current()->parameters();
            $response = \Event::fire('routes.translation', [ 'attributes' => $attributes ]);
            if ( !empty( $response ) )
            {
                $response = array_shift($response);
            }

            if ( is_array($response) )
            {
                $attributes = array_merge($attributes, $response);
            }
        }

        return $attributes;
    }

    /**
     * Build URL using array data from parse_url
     *
     * @param array|false $parsed_url Array of data from parse_url function
     *
     * @return string               Returns URL as string.
     */
    protected function unparseUrl( $parsed_url )
    {
        if ( empty( $parsed_url ) )
        {
            return "";
        }

        $url = "";
        $url .= isset( $parsed_url[ 'scheme' ] ) ? $parsed_url[ 'scheme' ] . '://' : '';
        $url .= isset( $parsed_url[ 'host' ] ) ? $parsed_url[ 'host' ] : '';
        $url .= isset( $parsed_url[ 'port' ] ) ? ':' . $parsed_url[ 'port' ] : '';
        $user = isset( $parsed_url[ 'user' ] ) ? $parsed_url[ 'user' ] : '';
        $pass = isset( $parsed_url[ 'pass' ] ) ? ':' . $parsed_url[ 'pass' ] : '';
        $url .= $user . ( ( $user || $pass ) ? "$pass@" : '' );

        if ( !empty( $url ) )
        {
            $url .= isset( $parsed_url[ 'path' ] ) ? '/' . ltrim($parsed_url[ 'path' ], '/') : '';
        } else
        {
            $url .= isset( $parsed_url[ 'path' ] ) ? $parsed_url[ 'path' ] : '';
        }

        $url .= isset( $parsed_url[ 'query' ] ) ? '?' . $parsed_url[ 'query' ] : '';
        $url .= isset( $parsed_url[ 'fragment' ] ) ? '#' . $parsed_url[ 'fragment' ] : '';

        return $url;
    }

    /**
     * Negotiates language with the user's browser through the Accept-Language
     * HTTP header or the user's host address.  Language codes are generally in
     * the form "ll" for a language spoken in only one country, or "ll-CC" for a
     * language spoken in a particular country.  For example, U.S. English is
     * "en-US", while British English is "en-UK".  Portuguese as spoken in
     * Portugal is "pt-PT", while Brazilian Portuguese is "pt-BR".
     *
     * This function is based on negotiateLanguage from Pear HTTP2
     * http://pear.php.net/package/HTTP2/
     *
     * Quality factors in the Accept-Language: header are supported, e.g.:
     *      Accept-Language: en-UK;q=0.7, en-US;q=0.6, no, dk;q=0.8
     *
     * @return string  The negotiated language result or app.locale.
     */
    public function negotiateLanguage()
    {
        $default = $this->configRepository->get('app.locale');
        $supported = array();
        foreach ( $this->configRepository->get('laravel-localization::supportedLocales') as $lang => $language )
        {
            $supported[ $lang ] = $lang;
        }

        if ( !count($supported) )
        {
            return $default;
        }

        if ( $this->request->header('Accept-Language') )
        {
            $matches = array();
            $generic_matches = array();
            foreach ( explode(',', $this->request->header('Accept-Language')) as $option )
            {
                $option = array_map('trim', explode(';', $option));

                $l = $option[ 0 ];
                if ( isset( $option[ 1 ] ) )
                {
                    $q = (float)str_replace('q=', '', $option[ 1 ]);
                } else
                {
                    $q = null;
                    // Assign default low weight for generic values
                    if ( $l == '*/*' )
                    {
                        $q = 0.01;
                    } elseif ( substr($l, -1) == '*' )
                    {
                        $q = 0.02;
                    }
                }
                // Unweighted values, get high weight by their position in the
                // list
                $q = isset( $q ) ? $q : 1000 - count($matches);
                $matches[ $l ] = $q;

                //If for some reason the Accept-Language header only sends language with country
                //we should make the language without country an accepted option, with a value
                //less than it's parent.
                $l_ops = explode('-', $l);
                array_pop($l_ops);
                while ( !empty( $l_ops ) )
                {
                    //The new generic option needs to be slightly less important than it's base
                    $q -= 0.001;
                    $op = implode('-', $l_ops);
                    if ( empty( $generic_matches[ $op ] ) || $generic_matches[ $op ] > $q )
                    {
                        $generic_matches[ $op ] = $q;
                    }
                    array_pop($l_ops);
                }
            }
            $matches = array_merge($generic_matches, $matches);

            arsort($matches, SORT_NUMERIC);

            foreach ( $matches as $key => $q )
            {
                if ( isset( $supported[ $key ] ) )
                {
                    return $supported[ $key ];
                }
            }
            // If any (i.e. "*") is acceptable, return the first supported format
            if ( isset( $matches[ '*' ] ) )
            {
                return array_shift($supported);
            }
        }

        if ( class_exists('Locale') && !empty( $_SERVER[ 'HTTP_ACCEPT_LANGUAGE' ] ) )
        {
            $http_accept_language = \Locale::acceptFromHttp($_SERVER[ 'HTTP_ACCEPT_LANGUAGE' ]);
            if ( in_array($http_accept_language, $supported) )
            {
                return $http_accept_language;
            }
        }

        if ( $this->request->server('REMOTE_HOST') )
        {
            $remote_host = explode('.', $this->request->server('REMOTE_HOST'));
            $lang = strtolower(end($remote_host));
            if ( isset( $supported[ $lang ] ) )
            {
                return $supported[ $lang ];
            }
        }

        return $default;
    }

}
