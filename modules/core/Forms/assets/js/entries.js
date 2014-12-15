(function($){

    App.module.controller("entries", function($scope, $rootScope, $http, $timeout){

        $scope.form = FORMDATA || {};

        // TODO: get from global config somehow
        var entriesPerPage = 10;

        var pagesCount = Math.ceil(FORMDATA.count / entriesPerPage);

        $scope.pagination = {
            current: null,
            pages: []
        };

        // Build range for ngRepeat
        // TODO: Create directive as in here: http://stackoverflow.com/a/11878038/1012616
        for (var i = 1; i <= pagesCount; i++) {
            $scope.pagination.pages.push(i);
        }

        // Load results for page
        // TODO: Probably move to App.js
        $scope.goToPage = function(index) {

            if (index === $scope.pagination.current) return;

            $http.post(App.route("/api/forms/entries"), {

                "form": angular.copy($scope.form),
                "sort": {"created": -1},
                "limit": entriesPerPage,
                "skip": entriesPerPage * (index - 1)

            }, {responseType:"json"}).success(function(data){

                if (data) $scope.entries = data;

                $scope.pagination.current = index;

            }).error(App.module.callbacks.error.http);
        }

        // Get first page
        $scope.goToPage(1);

        $scope.remove = function(index, entryId){

            App.Ui.confirm(App.i18n.get("Are you sure?"), function() {

                $http.post(App.route("/api/forms/removeentry"), {

                    "form": angular.copy($scope.form),
                    "entryId": entryId

                }, {responseType:"json"}).success(function(data){

                    $timeout(function(){
                        $scope.entries.splice(index, 1);
                        $scope.form.count -= 1;
                        App.notify(App.i18n.get("Entry removed"), "success");
                    }, 0);

                }).error(App.module.callbacks.error.http);
            });
        };


        // batch actions

        $scope.selected = null;

        $scope.$on('multiple-select', function(e, data){
            $timeout(function(){
                $scope.selected = data.items.length ? data.items : null;
            }, 0);
        });

        $scope.removeSelected = function(){
            if ($scope.selected && $scope.selected.length) {

                App.Ui.confirm(App.i18n.get("Are you sure?"), function() {

                    var row, scope, $index, $ids = [], form = angular.copy($scope.form);

                    for(var i=0;i<$scope.selected.length;i++) {
                        row    = $scope.selected[i],
                        scope  = $(row).scope(),
                        entry  = scope.entry,
                        $index = scope.$index;

                        (function(row, scope, entry, $index){

                            $http.post(App.route("/api/forms/removeentry"), {
                                "form": form,
                                "entryId": entry._id
                            }, {responseType:"json"}).error(App.module.callbacks.error.http);

                            $ids.push(entry._id);
                            $scope.form.count -= 1;

                        })(row, scope, entry, $index);
                    }

                    $scope.entries = $scope.entries.filter(function(entry){
                        return ($ids.indexOf(entry._id)===-1);
                    });
                });
            }
        };

        $scope.emptytable = function() {

            App.Ui.confirm(App.i18n.get("Are you sure?"), function() {
                $http.post(App.route("/api/forms/emptytable"), {

                    "form": angular.copy($scope.form)

                }, {responseType:"json"}).success(function(data){

                    $timeout(function(){
                        $scope.entries = [];
                        $scope.form.count = 0;
                        App.notify(App.i18n.get("Done."), "success");
                    }, 0);

                }).error(App.module.callbacks.error.http);
            });
        };

    });

})(jQuery);