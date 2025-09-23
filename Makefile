.PHONY: mappers clean install

mappers:
	php bin/console generate:mappers --clear

clean:
	rm -rf generated/Mapper/*

install:
	composer install
