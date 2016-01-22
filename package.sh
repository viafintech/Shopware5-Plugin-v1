#!/bin/bash
DST="barzahlen_shopware4_plugin_v1.0.6"
if [ -d $DST ]; then
rm -R $DST
fi
mkdir -p $DST/Frontend/ZerintPaymentBarzahlen
cp -r src/engine/Shopware/Plugins/Community/Frontend/ZerintPaymentBarzahlen/ $DST/Frontend/ZerintPaymentBarzahlen/
zip -r $DST.zip $DST/Frontend/
rm -R $DST