#!/bin/bash

TEST_OUTPUT=/tmp/copy_comment_test.output
for NAME in just_version_change.yml module_added.yml module_deleted.yml patch_added.yml patch_removed.yml complex.yml; do
  echo -n "- ${NAME}: "
  GENERATED=$(php ../copy_comments.php original.yml ${NAME} > $TEST_OUTPUT)
  DIFFS=$(diff -u $TEST_OUTPUT expected/$NAME)
  RESULT=$?

  if [ $RESULT -eq 0 ]; then
    echo "Passed"
  else
    echo -e "Failed!\n====="
    echo -e "The differences between expected and actual output are:\n"
    echo -e "$DIFFS\n=====\n"
  fi

done
