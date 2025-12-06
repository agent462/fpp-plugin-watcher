SRCDIR ?= /opt/fpp/src
include ${SRCDIR}/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libfpp-plugin-watcher.$(SHLIB_EXT)
debug: all

OBJECTS_fpp_watcher_so += src/WatcherMultiSync.o
LIBS_fpp_watcher_so += -L${SRCDIR} -lfpp -ljsoncpp -lhttpserver
CXXFLAGS_src/WatcherMultiSync.o += -I${SRCDIR}

%.o: %.cpp Makefile
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libfpp-plugin-watcher.$(SHLIB_EXT): $(OBJECTS_fpp_watcher_so) ${SRCDIR}/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_fpp_watcher_so) $(LIBS_fpp_watcher_so) $(LDFLAGS) -o $@

clean:
	rm -f libfpp-plugin-watcher.so $(OBJECTS_fpp_watcher_so)
