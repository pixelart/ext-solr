.. _routing-simplify-facets:

===================================
Simplify facets inside of the query
===================================

Sometimes you or your customer want to share links to a search result. The link itself could really hard to read, a specially if it contains a lot of filters.

There are two options that you can apply on the query string:

1. Concat multiple values of facet into a single string
2. Mask the filter inside of the query

All query related configurations will be places below node `query` below node `solr`:

.. code-block:: yaml

  routeEnhancers:
    products:
      solr:
        query:

Concat multiple values into a string
------------------------------------

In order to active concat of multiple value add entry `concat` and set the value to `true`.

By default the values will concat with a colon. If you need a different separator, add the entry `valueSeparator` and set a value that fits your need.

.. code-block:: yaml

  routeEnhancers:
    products:
      solr:
        query:
          concat: true
          # valueSeparator: ','

Before Solr concat the values, a alphanumeric sort applied.

Imaging following filter:

	tx_solr[filter][0]=taste:sweet&tx_solr[filter][1]=taste:sour&tx_solr[filter][2]=taste:matcha

By enable concat of multiple values, it would change into

	tx_solr[filter][0]=taste:matcha,sour,sweet


Mask filter inside of the query
-------------------------------

Mask filter inside of the URL allows you to make the URL more readable and to shrink the length.

The configuration contains two parts:

1. enable the mask
2. map from facet to parameter

Add the entry `mask` with the value `true` to the query configuration.

Add the node `map` and configure the mapping.

The value of the map defines the facet type, the value the parameter that will be used inside of the URL.

.. code-block:: yaml

  routeEnhancers:
    products:
      solr:
        query:
          mask: true
          map:
            taste: taste


Imaging following filter:

	tx_solr[filter][0]=taste:sweet&tx_solr[filter][1]=taste:sour&tx_solr[filter][2]=taste:matcha

By enable concat of multiple values, it would change into

	taste=matcha,sour,sweet

.. tip::
  The facet and the parameter can be equal, you don't have to choose different names.

.. note::
  By enable the mask option, the concat option applied automatically!

.. note::
  The mask only apply to facet you configure inside of the map.

.. important::
  Some facet names are excludes from mask until you configure a different parameter name. These are TYPO3 internal parameters.
  * type
  * id
  * no_cache
  * cHash
  * MP

Full example
------------

This example shows the all configuration done above

.. code-block:: yaml

  routeEnhancers:
    products:
      solr:
        query:
          # To reduce the amount of parameters you can force Solr to concat the values.
          # For example you have following filter:
          #   tx_solr[filter][0]=taste:sweet&tx_solr[filter][1]=taste:sour&tx_solr[filter][2]=taste:matcha
          #
          # Concat will:
          # 1. collect all filters of the same type
          # 2. will sort all filter values alpha numeric
          # 3. join the values together
          #
          # As a result the query will modified into:
          #   tx_solr[filter][0]=taste:matcha,sour,sweet
          #
          # Note: If you active the mask option, the concat feature turn on automatically
          #
          concat: true
          # valueSeparator: ','

          # You can tell Solr to mask query facets. This feature require the map below
          #
          # For example you have following filter:
          #   tx_solr[filter][0]=taste:sweet&tx_solr[filter][1]=taste:sour&tx_solr[filter][2]=taste:matcha
          # Mask will:
          # 1. implode all values into a single string and sort it -> green,red,yellow
          # 2. replace tx_solr[filter][]=color: with color=
          #
          # As a result the query will modified into:
          # taste=matcha,sour,sweet
          #
          mask: true

          # In order to simplify a filter argument, you have to define a corresponding map value
          # There is no automatically reduction of filter parameters at the moment available.
          # The key is the name of your facet, the value what use instead.
          #
          # Important:
          # There are some restrictions for the values. The use of TYPO3 core parameters is prohibited.
          # This contains at the moment following strings: no_cache, cHash, id, MP, type
          map:
            taste: taste