'use strict';

module.exports = function (grunt) {

    // Load grunt tasks automatically
    require('load-grunt-tasks')(grunt);

    // Define the configuration for all the tasks
    grunt.initConfig({
        clean: {
            dist: {
                files: [{
                    dot: true,
                    src: ['dist/{,*/}*', '.tmp/']
                }]
            }
        },

        spoonware: {
            dist: {
                files: [
                    {
                        expand: true,
                        cwd: 'client/',
                        src: '**.js',
                        dest: '.tmp/spoonware'
                    }
                ]
            }
        },

        uglify: {
            dist: {
                files: {
                    'dist/jskomment.min.js': [
                        '.tmp/spoonware/**.js'
                    ]
                }
            }
        },

        cssmin: {
            dist: {
                files: {
                    'dist/jskomment.css': [
                        'client/{,*/}*.css'
                    ]
                }
            }
        },

        copy: {
            dist: {
                files: [{
                    expand: true,
                    dot: true,
                    cwd: 'client/',
                    dest: 'dist/',
                    src: ['*gif']
                }]
            }
        }

    });

    grunt.registerTask('build', [
        'clean:dist',
        'spoonware',
        'uglify',
        'cssmin',
        'copy'
    ]);

    grunt.registerTask('default', ['build']);

};
