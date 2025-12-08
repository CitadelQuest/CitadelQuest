const Encore = require('@symfony/webpack-encore');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    // directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // public path used by the web server to access the output path
    .setPublicPath('/build')
    // only needed for CDN's or subdirectory deploy
    //.setManifestKeyPrefix('build/')

    /*
     * ENTRY CONFIG
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addEntry('app', './assets/entries/app.js')
    .addEntry('crypto', './assets/entries/crypto.js')
    .addEntry('backup', './assets/entries/backup.js')
    .addEntry('translations', './assets/entries/translations.js')
    .addEntry('visual-design', './assets/entries/visual-design.js')
    .addEntry('settings-general', './assets/entries/settings-general.js')
    .addEntry('js/toast', './assets/js/shared/toast.js')
    .addEntry('language-switcher', './assets/entries/language-switcher.js')
    .addEntry('diary', './assets/entries/diary.js')
    .addEntry('spirit', './assets/entries/spirit.js')
    .addEntry('spirit-chat', './assets/entries/spirit-chat.js')
    .addEntry('cq-chat-modal', './assets/entries/cq-chat-modal.js')
    .addEntry('welcome-onboarding', './assets/entries/welcome-onboarding.js')
    .addEntry('admin-dashboard', './assets/entries/admin-dashboard.js')
    .addEntry('admin-users', './assets/entries/admin-users.js')
    .addEntry('admin-migrations', './assets/entries/admin-migrations.js')
    .addEntry('file_browser', './assets/entries/file_browser.js')
    .addEntry('dashboard-badges', './assets/js/features/dashboard/dashboard-badges.js')
    .addEntry('database-vacuum', './assets/js/utils/database-vacuum.js')

    // Enable SASS/SCSS support
    .enableSassLoader()

    // Enable PostCSS support
    .enablePostCssLoader()

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .enableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()

    // Displays build status system notifications to the user
    // .enableBuildNotifications()

    .enableSourceMaps(!Encore.isProduction())
    // enables hashed filenames (e.g. app.abc123.css)
    .enableVersioning(Encore.isProduction())

    // configure Babel
    // .configureBabel((config) => {
    //     config.plugins.push('@babel/a-babel-plugin');
    // })

    // enables Sass/SCSS support
    .enableSassLoader()

    // enables and configure @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.38';
    })

    // enables Sass/SCSS support
    //.enableSassLoader()

    // uncomment if you use TypeScript
    //.enableTypeScriptLoader()

    // uncomment if you use React
    //.enableReactPreset()

    // uncomment to get integrity="..." attributes on your script & link tags
    // requires WebpackEncoreBundle 1.4 or higher
    //.enableIntegrityHashes(Encore.isProduction())

    // uncomment if you're having problems with a jQuery plugin
    //.autoProvidejQuery()
;

module.exports = Encore.getWebpackConfig();
