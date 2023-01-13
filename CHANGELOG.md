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

#### 0.1.9 - 2022-10-25

- changes to error handling - more emphasis on user handling and increased logging

#### 0.2.0 - 2022-11-07

- fix to image handling and squashing

#### 0.2.1 - 2022-11-21

- configurable depth options per collection
- some prep work for better field handling

#### 0.2.3 - 2022-11-22

- add support for custom types with parameters

#### 0.2.4 - 2022-11-28

- fix an issue for storing image attributes with multi image objects

#### 0.2.5 - 2022-12-12

- fix an issue for uploading multiple files
- fix an issue for direct uploading to api

#### 0.2.6 - 2023-01-10

- small tidy of core file
- BREAKING CHANGES TO FILTER
- added simple filtering with AND and OR

### 0.2.7

- begin prep for other Http function
- add timeout variable for curl
- add deprecated warnings
