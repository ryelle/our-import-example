# Our Import

An example command for WP-CLI, to import content from a custom CMS's database. You can just read right through master and follow the comments, or go through it step-by-step through the other branches.

This example was presented at the Boston WP Meetup in May 2013. [The slides accompanying it can be found here](http://redradar.net/slides/wp-cli/).

The starter script that this leads to is in the [`starter-script`](https://github.com/ryelle/our-import-example/tree/starter-script) branch.

## Step 1: Files [`step-one`](https://github.com/ryelle/our-import-example/tree/step-one)
In this step we've created `our-plugin.php` & `our-cli.php`. We put these files in a folder in `wp-content/plugins`, and activate it in the WP admin. We've also set up the class & named our command `ourport`.


## Step 2: Basic subcommand [`step-two`](https://github.com/ryelle/our-import-example/blob/step-two/our-cli.php)
We've created the subcommand `hello`, to show how to use arguments passed in from the command line.


## Step 3: Database connection [`step-three`](https://github.com/ryelle/our-import-example/blob/step-three/our-cli.php)
Since we're importing from a second database, we need to create a new database connection. My preference is to create a setup function that populates a member variable, which is what this step does. We also create a new subcommand `test` to make sure we've connected.


## Step 4: Import a single post [`step-four`](https://github.com/ryelle/our-import-example/blob/step-four/our-cli.php)
In this step we've created three new functions, but only one of them is a subcommand. Any public method of your class will be a subcommand, you need to specify `private` if it's just a utility. So we've created the subcommand `update` which requires an ID, and pulls that one post out of our source database. Then we run it through `_import`. Read through these functions - the important parts are [`wp_insert_post`](http://codex.wordpress.org/Function_Reference/wp_insert_post), [`update_post_meta`](http://codex.wordpress.org/Function_Reference/update_post_meta). [`set_post_format`](http://codex.wordpress.org/Function_Reference/set_post_format) can also be a useful function, and checking out [the functions related to `wp_insert_post`](http://codex.wordpress.org/Function_Reference/wp_insert_post#Related) would also be smart.

At this point you can run `wp ourport update <id>` and you'll see your new post!


## Step 4.5: Attach taxonomies [`step-four-five`](https://github.com/ryelle/our-import-example/blob/step-four-five/our-cli.php)
Here (starting at line 100), we pull out tags and categories from the source database. Tags can be inserted directly as text, so we don't need to change the `$terms` result except to manipulate it into the correct format for `wp_set_post_terms`. Categories need to be the term_id, so we do an `array_walk` to convert each category to a WordPress category ID (note: this function is not actually written, but you would use `term_exists` to get the term ID if it exists, and if not you can create it with `wp_insert_term`). Important functions here are [`wp_set_post_terms`](http://codex.wordpress.org/Function_Reference/wp_set_post_terms) and [`wp_list_pluck`](http://codex.wordpress.org/Function_Reference/wp_list_pluck).


## Step 5: Import media [`step-five`](https://github.com/ryelle/our-import-example/blob/step-five/our-cli.php)
By now we've tried the import and can see it imports a post, but maybe we also need the media moved into the media library. To do this, we'll use a regular expression to grab all the `<img>` tags & pull out the URL. From here we do a little checking to make sure we want to import it, and then using `media_sideload_image`, we download the image, and move it into the library. This returns HTML of the image tag, so using the original `$img` we saved, we simply string-replace the old HTML with the new. Now that we've changed the content, we need to update the post with `wp_update_post`.

The important functions here are [`media_sideload_image`](http://codex.wordpress.org/Function_Reference/media_sideload_image) & [`wp_update_post`](http://codex.wordpress.org/Function_Reference/wp_update_post).


## Step 6: Import all posts [`step-six`](https://github.com/ryelle/our-import-example/blob/step-six/our-cli.php)
Create a new subcommand to grab all items from the database. While there are items, we'll grab an item and run `import` on it, just like we do in the single post step. You can pass arguments here to skip certain posts (maybe you have a list of IDs already imported).


## References
- [WP-CLI](http://wp-cli.org/)- see here for install instructions & basic use.
- All the codex pages linked
- [WP-CLI's Commands Cookbook](https://github.com/wp-cli/wp-cli/wiki/Commands-Cookbook)
- [$wpdb documentation](http://codex.wordpress.org/Class_Reference/wpdb) for reference on the WP database
