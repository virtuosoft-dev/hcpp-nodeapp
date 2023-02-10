/**
 * A simple Hello World! Node.js application that listens
 * on the port number specified in the command line.
 */

const express = require('express')
const app = express()

// Get the port number from the command line's -p argument
let argv = require('minimist')(process.argv.slice(2));
let port = argv.p || 0;
if (port == 0) {
    console.log("Port number is not specified");
    process.exit(1);
}

app.get('*', (req, res) => {
  res.send('Hello World!');
});

app.listen(port, () => {
  console.log(`Example app listening on port ${port}`);
});
