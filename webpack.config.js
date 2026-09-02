const path = require('path')

module.exports = {
    entry: {
        'composer-editor': './src/composer-editor.js',
    },

    output: {
        path: path.resolve(
            __dirname,
            'js'
        ),

        filename: '[name].js',

        /*
         * main.js und unsere anderen Dateien
         * niemals automatisch löschen.
         */
        clean: false,
    },

    module: {
        rules: [
            {
                test: /\.css$/i,

                use: [
                    'style-loader',
                    'css-loader',
                ],
            },
        ],
    },

    performance: {
        hints: false,
    },

    devtool: false,
}