# Changelog

All notable changes to `strapi-wrapper` will be documented in this file.

#### 0.1.0 - 2022-06-22

- initial release

#### 0.1.1 - 2022-09-22

- For strapi v4 add ability to deeply populate relationship queries
  ```ie $collection->populate(['City', 'Person'])```
  will populate the records of those two fields

#### 0.1.2 - 2022-09-23

- Small fixes to catch meta responses not being set on strapi records
- Add ability to set custom endpoint after the collection has been initialised
- Fix an issue when trying to squash a non array dataset
- Improve squash behavior for single returned collections
- Clean up filter return values
- Add deep filtering for strapi v4

#### 0.1.3 - 2022-09-23 - Hotfix

- Correct return type for custom query function

#### 0.1.4 - 2022-09-27

- Expand caching for collections
- Provide methods for cleaning cache

#### 0.1.5

- More configuration options
- Support for deep queries provided by https://github.com/Barelydead/strapi-plugin-populate-deep

#### 0.1.6

- added ability to mass clear all filters on collection

#### 0.1.7 - 2022-10-25

- changes to error handling - more emphasis on user handling and increased logging
