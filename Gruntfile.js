module.exports = function( grunt ) {

	'use strict';

	// POT regeneration is handled by `composer pot` (WP-CLI's
	// `wp i18n make-pot`), not Grunt — `grunt-wp-i18n` is unmaintained
	// and was already being bypassed (the committed POT's `X-Generator`
	// header reads `WP-CLI 2.9.0`). Grunt now only owns SCSS/CSS.

	// Project configuration
	grunt.initConfig( {

		pkg: grunt.file.readJSON( 'package.json' ),

		watch: {
			scss: {
				files: ['src/assets/src/*.scss'],
				tasks: ['sass:dist', 'postcss:dist']
			},
			options: {
				livereload: true
			}
		},

		postcss: {
			options: {
				map: false,
				processors: [
					require('autoprefixer')
				]
			},
			dist: {
				src: 'src/assets/trustedlogin.css'
			}
		},

		sass: {
			options: {
				style: 'compressed',
				sourceMap: false,
				noCache: true,
			},
			dist: {
				files: [{
					expand: true,
					cwd: 'src/assets/src',
					src: ['trustedlogin.scss'],
					dest: 'src/assets',
					ext: '.css'
				}]
			}
		},
	} );

	grunt.loadNpmTasks( 'grunt-contrib-sass' );
	grunt.loadNpmTasks( 'grunt-contrib-watch' );
	grunt.loadNpmTasks( 'grunt-postcss' );
	grunt.registerTask( 'default', [ 'sass', 'watch' ] );

	grunt.util.linefeed = '\n';

};
