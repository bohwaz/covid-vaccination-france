all: update report commit

update:
	php fetch.php

report:
	php report.php > docs/report.html

commit:
	git pull && git commit *.sqlite docs/report.html -m "Update data" && git push