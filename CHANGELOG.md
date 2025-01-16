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

### 0.2.8

- initial StrapiImage handler
- Collection Update and delete

### 0.2.9 - 2023-01-08

- add cache findOne and getCustom

### 0.3.0 - 2023-02-16

- sort by multiple fields

### 0.3.1 - 2023-03-31

- option to not flatten results

### 0.3.2 - 2023-06-23

- change to reduce unnecessary fields in query string
- change to catch different exceptions

### 0.3.3 - 2023-09-06

- update composer

### 0.4.0 - 2023-10-25

- Remove deprecated functions
- Discontinue support for API version 3
- Improve fetching by entry id
- Fixed filter change
- Bumped dependency versions

### 0.4.1 - 2024-10-22

- Remove deprecated functions
- add new variable to all insecure ssl
  STRAPI_VERIFY=false to allow insecure

### 0.4.2 - 2024-10-23

Re-upload of previous version

### 0.4.3 - 2024-10-29

Fix support for multiple filters

### 5.0.0 - 2025-01-09

- Add compatibility for Strapi v5.
- Introduce `STRAPI_V4_COMPATIBILITY` configuration to optionally enable v4 response format in v5.
- Update URL handling logic to support both v4 and v5 API structures seamlessly.
- Improve pagination and population query building to align with version-specific naming conventions.
- Enhance HTTP client logic to add compatibility headers and support secure/insecure SSL verification.

### 5.0.0 - 2025-01-09

- Add filter compatibility for strapi v5

### 5.0.3 - 2025-01-13

- Add Strapi v5 post compatibility

### 5.0.4 - 2025-01-16

- Listen to correct response
