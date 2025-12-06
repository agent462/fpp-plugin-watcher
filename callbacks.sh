#!/bin/bash

# fpp-plugin-watcher callbacks script
# Indicates this plugin has a C++ component

for var in "$@"
do
	case $var in
		-l|--list)
			echo "c++";
			exit 0;
		;;
		-h|--help)
			exit 0
		;;
		--)
			break
		;;
		*)
			printf "Unknown option %s\n" "$var"
			exit 1
		;;
	esac
done
