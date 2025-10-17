#!/bin/bash

# Sprawdzenie, czy podano komentarz
if [ -z "$1" ]; then
  echo "Użycie: ./gitpush.sh \"Twój komentarz do commita\""
  exit 1
fi

COMMENT="$1"

# Dodanie wszystkich zmian
git add .

# Commit z podanym komentarzem
git commit -m "$COMMENT"

# Wypchnięcie do domyślnego remote
git push

