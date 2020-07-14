<template>
  <v-card>
    <v-card-text>
      <v-layout row wrap>
        <v-flex sm12>
          <v-layout row wrap>
            <!-- FILTER DATE TIME -->
            <v-flex md2>
              <v-text-field
                class="body-2"
                v-model="filter.from"
                name="name"
                type="datetime-local"
                label="Date From"
                hide-details
              ></v-text-field>
            </v-flex>
            <v-flex md2>
              <v-text-field
                class="body-2"
                v-model="filter.to"
                name="name"
                type="datetime-local"
                label="Date To"
                hide-details
              ></v-text-field>
            </v-flex>
            <v-flex md2>
              <v-btn color="default" style="height: 50px !important" @click="filterGps">
                <v-icon dark>mdi-filter</v-icon>Filter
              </v-btn>
            </v-flex>
            <!-- END FILTER DATE TIME -->
            <!-- SPEED LEGEND (SLOW, MODERATE, FAST) -->
            <v-flex md6 style="height: 50px !important; padding: 15px 0px 0px;">
              <b>SPEED LEGEND (KPH):</b>
              <span v-for="item in masterSpeed" :key="item.color">
                <v-chip
                  class="ma-2"
                  :style="`height: 24px !important; background-color:${ item.color } !important; border-color:${ item.color }`"
                >
                </v-chip>
                {{ item.from }} - {{ item.to }} ({{ item.name }})
              </span>
            </v-flex>
            <!-- END SPEED LEGEND (SLOW, MODERATE, FAST) -->
          </v-layout>
        </v-flex>
        <!-- MEDIA (PLAY, PAUSE, STOP, FAST FORWARD) -->
        <v-flex sm12 v-if="showMedia">
          <v-btn :disabled="mediaButtons[0].disabled" color="default" @click="play">
            <v-icon dark>mdi-play</v-icon>Play
          </v-btn>
          <v-btn :disabled="mediaButtons[1].disabled" color="default" @click="pause">
            <v-icon dark>mdi-pause</v-icon>Pause
          </v-btn>
          <v-btn :disabled="mediaButtons[2].disabled" color="default" @click="stop">
            <v-icon dark>mdi-stop</v-icon>Stop
          </v-btn>
          <v-btn :disabled="mediaButtons[3].disabled" color="default" @click="fastForward">
            <v-icon dark>mdi-fast-forward</v-icon>Fast Forward
          </v-btn>
        </v-flex>
        <!-- END MEDIA (PLAY, PAUSE, STOP, FAST FORWARD) -->
        <!-- MAP -->
        <v-flex sm12>
          <div style="background: peachpuff ; min-height: 700px; width: 100%" id="map"></div>
        </v-flex>
        <!-- END MAP -->
      </v-layout>
    </v-card-text>
  </v-card>
</template>
<script>
export default {
  data() {
    return {
      map: null,
      showMedia: false,
      filter: {
        from: null,
        to: null
      },
      mediaButtons: [
        {
          name: "play",
          disabled: false
        },
        {
          name: "pause",
          disabled: true
        },
        {
          name: "stop",
          disabled: true
        },
        {
          name: "fast forward",
          disabled: true
        }
      ],
      panorama: null,
      polyline: null,
      streetViewLat: null,
      streetViewLng: null,
      markerPaths: [],
      pathWithStroke: [],
      allPathWithStroke: [],
      oldPathWithStroke: {},
      $allPolylineObject: {},
      distance: 0,
      oldDistance: 0,
      oldSpeed: 0,
      masterSpeed: [],
      speedDistance: [],
      heading: null,
      prevHeading: null,
      needle: {
        minDistance: 9999999999,
        index: -1,
        latlng: null
      },
      newNeedle: {
        minDistance: 9999999999,
        index: -1,
        latlng: null
      },
      hayStack: [],
      nextHaystack: null,
      loop: null,
      startingIndex: 0,
      playingIndex: 0,
      limit: 1000,
      lineSymbol: {},
      hasMapCentered: false
    };
  },
  computed: {},
  created() {
    let vm = this;

    // initialize your default filter value
    vm.filter.from = vm.$moment().format("YYYY-MM-DDT00:00");
    vm.filter.to = vm.$moment().format("YYYY-MM-DDT23:59");

    // click event to open street view
    document.addEventListener("click", function(e) {
      if (e.target && e.target.id == "open-street-view") {
        vm.openStreetView();
      }
    });
  },
  mounted() {
    let vm = this;
    vm.init();
  },
  methods: {
    async init() {
      let vm = this;

      // Create the instance of a map
      vm.map = new google.maps.Map(document.getElementById("map"), {
        center: {
          lat: 14.5995,
          lng: 120.9842
        },
        zoom: 15,
        streetViewControl: false
      });

      // We get the map's default panorama and set up some defaults.
      // Note that we don't yet set it visible.
      vm.panorama = vm.map.getStreetView();

      // Hide media buttons if street view is closed
      google.maps.event.addListener(
        vm.map.getStreetView(),
        "visible_changed",
        function() {
          if (!this.getVisible()) {
            vm.showMedia = false;
          }
        }
      );

      // Icon for the plotting of latitude, longitude
      vm.lineSymbol = {
        path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
        strokeOpacity: 1,
        strokeWeight: 3.5,
        scale: 3
      }

      // Get the list of master table speed
      let { data } = await axios.get("/master-speeds");

      vm.masterSpeed = data;
      
      // Initialize default path (GREEN)
      vm.allPathWithStroke["#3a9c3e"] = [];
      vm.masterSpeed.forEach(function(object) {
        vm.allPathWithStroke[object.color] = [];
      });

      vm.plot(vm.limit, 0);
    },
    async plot(limit, offset) {
      let vm = this;

      // Get your data
      let { data } = await axios.get(
        `/gps-tracker/batch-list/${vm.$route.params.id}?limit=${limit}&offset=${offset}&from=${vm.filter.from}&to=${vm.filter.to}`
      );

      if (data.length > 0) {
        for (var i = 0; i < data.length; i++) {
          // If first data and map doesn't have center yet, set center
          if (i == 0 && !vm.hasMapCentered) {
            vm.map.setCenter({
              lat: data[i].latitude === null ? 0 : parseFloat(data[i].latitude),
              lng:
                data[i].longitude === null ? 0 : parseFloat(data[i].longitude)
            });
          }

          vm.distance = data[i].distance + vm.oldDistance;

          // It is used for clicking the location and is hidden
          // If we use the allPathWithStroke, we will be having a hard time clicking it because it is a symbol/icon
          vm.pathWithStroke.push({
            lat: data[i].latitude === null ? 0 : parseFloat(data[i].latitude),
            lng: data[i].longitude === null ? 0 : parseFloat(data[i].longitude),
            speed: data[i].speed,
            distance: vm.distance.toFixed(2)
          });

          vm.speedDistance.push({
            distance: vm.distance.toFixed(2),
            speed: data[i].speed
          });

          // If no color found, default color is green
          if (!data[i].color) {
            data[i].color = "#3a9c3e";
          }

          // If there is previous path and the color/speed is changed
          // Add the previous path with the new color/speed as a starting point for the new color path
          // e.g. from green to yellow (slow to moderate)
          if (
            !_.isEmpty(vm.oldPathWithStroke) &&
            vm.oldSpeed != data[i].color &&
            vm.oldSpeed !== null
          ) {
            vm.allPathWithStroke[data[i].color].push(vm.oldPathWithStroke);
          }

          // Push the new path
          vm.allPathWithStroke[data[i].color].push({
            lat: data[i].latitude === null ? 0 : parseFloat(data[i].latitude),
            lng: data[i].longitude === null ? 0 : parseFloat(data[i].longitude)
          });

          // Save the old speed used for checking if speed is changed or not
          vm.oldSpeed = data[i].color;

          // Plot all the path that is not the current color/speed
          vm.masterSpeed.forEach(function(object) {
            // green !== red
            if (data[i].color !== object.color) {
              if (vm.allPathWithStroke[object.color].length > 0) {

                var lineSymbol = {
                  path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                  strokeOpacity: 1,
                  strokeWeight: 3.5,
                  strokeColor: object.color,
                  scale: 3
                };

                vm.$allPolylineObject = new google.maps.Polyline({
                  path: vm.allPathWithStroke[object.color],
                  strokeOpacity: 0,
                  icons: [
                    {
                      icon: lineSymbol,
                      offset: "0",
                      repeat: "30px"
                    }
                  ]
                });
                vm.$allPolylineObject.setMap(vm.map);
                vm.allPathWithStroke[object.color] = [];
              }
            }
          });

          // Save the old path used for checking if color/speed is changed or not
          vm.oldPathWithStroke = {
            lat: data[i].latitude === null ? 0 : parseFloat(data[i].latitude),
            lng: data[i].longitude === null ? 0 : parseFloat(data[i].longitude)
          };
  
          vm.oldDistance += data[i].distance;
        }

        // Increase offset with the limit and run the function again
        offset += limit;
        vm.plot(limit, offset);
      } else { // If no data found, plot the remaining path in the array
        vm.masterSpeed.forEach(function(object) {
          if (vm.allPathWithStroke[object.color].length > 0) {

            var lineSymbol = {
              path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
              strokeOpacity: 1,
              strokeWeight: 3.5,
              strokeColor: object.color,
              scale: 3
            };

            // vm.lineSymbol.strokeColor = object.color;
            vm.$allPolylineObject = new google.maps.Polyline({
              path: vm.allPathWithStroke[object.color],
              strokeOpacity: 0,
              icons: [
                {
                  icon: lineSymbol,
                  offset: "0",
                  repeat: "30px"
                }
              ]
            });

            vm.$allPolylineObject.setMap(vm.map);

            vm.allPathWithStroke[object.color] = [];
          }
        });

        // Add a click event to open dialog for open street view
        vm.addClickEventPolyline(vm.pathWithStroke);
      }
    },
    addClickEventPolyline(path) {
      let vm = this;

      // Plot all the path but is hidden
      vm.polyline = new google.maps.Polyline({
        path: path,
        strokeOpacity: 0,
        strokeWeight: 6
      });

      vm.polyline.setMap(vm.map);

      // Add a click event to open the dialog box
      google.maps.event.addListener(vm.polyline, "click", function(event) {
        vm.needle = {
          minDistance: 9999999999,
          index: -1,
          latlng: null
        };

        var latlng = event.latLng;

        // GET CLOSEST ON CLICKED
        vm.polyline.getPath().forEach(function(routePoint, index) {
          var dist = google.maps.geometry.spherical.computeDistanceBetween(
            routePoint,
            latlng
          );
          if (dist < vm.needle.minDistance) {
            vm.needle.minDistance = dist;
            vm.needle.index = index;
            vm.needle.speed = path[index].speed;
            vm.needle.distance = path[index].distance;
            vm.needle.latlng = routePoint;
          }
        });

        let infowindow = new google.maps.InfoWindow({
          content: `
            <a href="javascript:void(0)" id="open-street-view"><i class="fas fa-street-view"></i>&nbsp;Open Street View</a>
          `
        });

        // Set lat long for street view starting position
        vm.streetViewLat = latlng.lat();
        vm.streetViewLng = latlng.lng();

        infowindow.setPosition(latlng);
        infowindow.open(vm.map);
      });
    },
    openStreetView() {
      let vm = this;
      var count = 1;
      
      // Get the next 5 paths to set the heading
      // It's to make the street view facing the next path
      vm.polyline.getPath().forEach(function(routePoint, index) {
        if (count == 2) {
          vm.hayStack.push({ index: index, latlng: routePoint });
          count = 1;
        }
        if (vm.needle.index == index - 2) {
          vm.newNeedle.index = index;
          vm.newNeedle.latlng = routePoint;
          vm.hayStack.push({ index: index, latlng: routePoint });
        }
        count++;
      });

      vm.startingIndex = vm.searchArray(vm.newNeedle.index, vm.hayStack);
      vm.playingIndex = vm.searchArray(vm.newNeedle.index, vm.hayStack);

      var streetViewService = new google.maps.StreetViewService();
      var STREETVIEW_MAX_DISTANCE = 10;
      streetViewService.getPanoramaByLocation(
        vm.newNeedle.latlng,
        STREETVIEW_MAX_DISTANCE,
        function(streetViewPanoramaData, status) {
          if (status === google.maps.StreetViewStatus.OK) {
            // show media buttons (PLAY, PAUSE, STOP, FAST FORWARD)
            vm.showMedia = true;

            // Enable play
            // Disable pause, stop, fast forward
            for (var i = 0; i < vm.mediaButtons.length; i++) {
              if (i == 0) {
                vm.mediaButtons[i].disabled = false;
              } else {
                vm.mediaButtons[i].disabled = true;
              }
            }

            // Compute the angle between 1st point and next point
            // So that the street view is facing at the next point
            vm.heading = google.maps.geometry.spherical.computeHeading(
              vm.needle.latlng,
              vm.newNeedle.latlng
            );

            // Default position when street view is called
            vm.setPosition(vm.streetViewLat, vm.streetViewLng);
            // Set point of view between 1st point and next point
            vm.setPov(vm.heading, 0);

            vm.panorama.setVisible(true);
          } else {
             alert("No location found.");
          }
        }
      );
    },
    streetViewLoop(i, timer) {
      let vm = this;
      vm.loop = setTimeout(function() {
        if (i >= vm.hayStack.length - 1) {
          vm.nextHaystack = vm.hayStack[i];
          vm.heading = vm.prevHeading;
        } else {
          vm.nextHaystack = vm.hayStack[i + 1];
          vm.heading = google.maps.geometry.spherical.computeHeading(
            vm.hayStack[i].latlng,
            vm.nextHaystack.latlng
          );
        }

        vm.setPosition(
          vm.hayStack[i].latlng.lat(),
          vm.hayStack[i].latlng.lng()
        );

        vm.setPov(vm.heading, 0);

        vm.prevHeading = vm.heading;
        vm.panorama.setVisible(true);
        vm.panorama = vm.map.getStreetView();
        i++;
        vm.playingIndex++;
        if (i < vm.hayStack.length) {
          vm.streetViewLoop(i, timer);
        } else {
          vm.playingIndex = vm.startingIndex;
          clearTimeout(vm.loop);
        }
      }, timer);
    },
    setPosition(lat, lng) {
      let vm = this;

      vm.panorama.setPosition({
        lat: lat,
        lng: lng
      });
    },
    setPov(heading, pitch) {
      let vm = this;
      vm.panorama.setPov(
        /* @type {google.maps.StreetViewPov} */ ({
          // defines the rotation angle around the camera locus in degrees relative from true north.
          heading: heading,
          // defines the angle variance "up" or "down" from the camera's initial default pitch, which is often (but not always) flat horizontal.
          pitch: pitch
        })
      );
    },
    stopLoop() {
      let vm = this;

      clearTimeout(vm.loop);
    },
    play() {
      let vm = this;

      for (var i = 0; i < vm.mediaButtons.length; i++) {
        if (i == 0) {
          vm.mediaButtons[i].disabled = true;
        } else {
          vm.mediaButtons[i].disabled = false;
        }
      }

      vm.stopLoop();
      vm.streetViewLoop(vm.playingIndex, 3000);
    },
    pause() {
      let vm = this;

      for (var i = 0; i < vm.mediaButtons.length; i++) {
        if (i == 1) {
          vm.mediaButtons[i].disabled = true;
        } else {
          vm.mediaButtons[i].disabled = false;
        }
      }

      vm.stopLoop();
    },
    stop() {
      let vm = this;

      for (var i = 0; i < vm.mediaButtons.length; i++) {
        if (i == 2) {
          vm.mediaButtons[i].disabled = true;
        } else {
          vm.mediaButtons[i].disabled = false;
        }
      }

      var heading = google.maps.geometry.spherical.computeHeading(
        vm.needle.latlng,
        vm.newNeedle.latlng
      );

      vm.setPosition(vm.needle.latlng.lat(), vm.needle.latlng.lng());
      vm.setPov(heading, 0);
      vm.panorama.setVisible(true);

      vm.stopLoop();

      vm.playingIndex = vm.startingIndex;
    },
    fastForward() {
      let vm = this;

      for (var i = 0; i < vm.mediaButtons.length; i++) {
        if (i == 3) {
          vm.mediaButtons[i].disabled = true;
        } else {
          vm.mediaButtons[i].disabled = false;
        }
      }

      vm.stopLoop();
      vm.streetViewLoop(vm.playingIndex, 1000);
    },
    searchArray(searchValue, array) {
      for (var i = 0; i < array.length; i++) {
        if (array[i].index === searchValue) {
          return i;
        }
      }
    },
    async filterGps() {
      let vm = this;

      vm.markerPaths = [];
      vm.pathWithStroke = [];
      vm.allPathWithStroke = [];
      vm.oldPathWithStroke = {};
      vm.$allPolylineObject = {};
      vm.hayStack = [];

      // get the list of master table speed
      let { data } = await axios.get("/master-speeds");

      vm.init();
    }
  }
};
</script>
