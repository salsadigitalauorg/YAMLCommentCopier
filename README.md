YAML comment copier.

This PHP script exists to address the fact that PHP YAML support doesn't
include a way to retain comments.

The script takes an original version of a YAML file and a new version, and
outputs a version of the new file that includes comments in the same positions
that they had in the old file.

To invoke the script, call it as:

php copy_comments.php <old_file_name> <new_file_name> > <output_file_name>
