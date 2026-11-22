package api

import "os"

// lookupEnv is a thin seam over os.LookupEnv so the warehouse-authoritative flag can be tested.
var lookupEnv = os.LookupEnv
